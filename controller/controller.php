<?php

namespace Pterodactyl\Http\Controllers\Admin\Extensions\safetyblur;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary as BlueprintExtensionLibrary;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;

class safetyblurExtensionController extends Controller
{
    public function __construct(
        private SettingsRepositoryInterface $settings,
        private BlueprintExtensionLibrary $blueprint
    ) {
    }

    public function index()
    {
        // Check if license was previously validated
        $licenseKey = $this->blueprint->dbGet('safetyblur', 'license_key');
        $licenseValid = false;

        // Perform live check against API if a key exists; don't trust DB flags
        if ($licenseKey) {
            $licenseValid = $this->performHeartbeatCheck($licenseKey);
        }
        
        return view('admin.extensions.safetyblur.index', [
            'blueprint' => $this->blueprint,
            'licenseValid' => $licenseValid,
        ]);
    }

    private function performHeartbeatCheck(string $licenseKey): bool
    {
        // SECURITY: Hardcoded verification secret - matches API server
        // This is compiled into the distributed code, not in a separate file
        $verificationSecret = 'lwmmAa4/xXYtuMj6ti9dR7XICV9X52PxFfgqR1BPf2M=';
        
        // Load API URL from license.json (if exists) or use default
        $licenseConfigPath = base_path('.blueprint/extensions/safetyblur/private/license.json');
        $apiUrl = 'https://api.lennox-rose.com/v1/blueprint/safetyblur/verify';
        
        if (file_exists($licenseConfigPath)) {
            $licenseConfig = json_decode(file_get_contents($licenseConfigPath), true);
            $apiUrl = $licenseConfig['api_url'] ?? $apiUrl;
        }
        
        // SECURITY: Check if this controller file has been modified
        $controllerPath = __FILE__;
        $controllerHash = hash_file('sha256', $controllerPath);
        
        // Get current domain info
        $domain = request()->getHost();
        $ownerName = $this->settings->get('settings::app:name', 'Unknown');
        $panelVersion = config('app.version', 'Unknown');
        $ipAddress = $this->getPublicIp();
        
        try {
            // Send heartbeat check to API with file integrity hash
            $response = Http::timeout(5)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'SafetyBlur-Extension/1.0',
                ])
                ->post($apiUrl, [
                    'key' => $licenseKey,
                    'product' => 'safetyblur',
                    'info' => [
                        'domain' => $domain,
                        'owner_name' => $ownerName,
                        'panel_version' => $panelVersion,
                        'ip_address' => $ipAddress,
                        'controller_hash' => $controllerHash, // Send file hash to API
                    ]
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                \Log::info('SafetyBlur Heartbeat Response', ['data' => $data]);
                
                // Verify the response is genuine using signature
                if (!isset($data['status'], $data['signature'], $data['timestamp'])) {
                    \Log::error('SafetyBlur: Missing required fields in response');
                    return false;
                }
                
                // Check timestamp is recent (within 60 seconds)
                $timeDiff = abs(time() - $data['timestamp']);
                if ($timeDiff > 60) {
                    \Log::error('SafetyBlur: Timestamp too old', ['diff' => $timeDiff]);
                    return false;
                }
                
                // Verify signature: HMAC-SHA256(license_key|timestamp|domain, secret)
                $expectedSignature = hash_hmac(
                    'sha256',
                    $licenseKey . '|' . $data['timestamp'] . '|' . $domain,
                    $verificationSecret
                );
                
                \Log::info('SafetyBlur Signature Check', [
                    'expected' => $expectedSignature,
                    'received' => $data['signature'],
                    'payload' => $licenseKey . '|' . $data['timestamp'] . '|' . $domain
                ]);
                
                if (!hash_equals($expectedSignature, $data['signature'])) {
                    // Signature mismatch - response was tampered with or forged
                    \Log::error('SafetyBlur: Signature mismatch');
                    return false;
                }
                
                // Signature valid, check status
                if ($data['status'] === 'good') {
                    \Log::info('SafetyBlur: License valid!');
                    return true;
                }
                \Log::warning('SafetyBlur: License status not good', ['status' => $data['status']]);
                return false;
            } else {
                // If API is unreachable, treat as invalid to prevent bypass
                return false;
            }
        } catch (\Exception $e) {
            // On error default to invalid
            return false;
        }
    }

    // Blueprint auto-registers this as admin.extensions.safetyblur.post
    public function post(Request $request): RedirectResponse
    {
        // Check which action is being performed
        if ($request->has('license_key')) {
            return $this->verifyLicense($request);
        }
        
        // Default to settings save
        return $this->saveSettings($request);
    }

    public function verifyLicense(Request $request): RedirectResponse
    {
        $request->validate([
            'license_key' => 'required|string',
        ]);

        $licenseKey = $request->input('license_key');
        
        // SECURITY: Get controller file hash
        $controllerHash = hash_file('sha256', __FILE__);
        
        // Load API URL from license.json (if exists) or use default
        $licenseConfigPath = base_path('.blueprint/extensions/safetyblur/private/license.json');
        $apiUrl = 'https://api.lennox-rose.com/v1/blueprint/safetyblur/verify';
        
        if (file_exists($licenseConfigPath)) {
            $licenseConfig = json_decode(file_get_contents($licenseConfigPath), true);
            $apiUrl = $licenseConfig['api_url'] ?? $apiUrl;
        }
        
        // Get domain and owner info
        $domain = $request->getHost();
        $ownerName = $this->settings->get('settings::app:name', 'Unknown');
        $panelVersion = config('app.version', 'Unknown');
        $ipAddress = $this->getPublicIp();
        
        $requestData = [
            'key' => $licenseKey,
            'product' => 'safetyblur',
            'info' => [
                'domain' => $domain,
                'owner_name' => $ownerName,
                'panel_version' => $panelVersion,
                'ip_address' => $ipAddress,
                'controller_hash' => $controllerHash,
            ]
        ];
        
        \Log::info('SafetyBlur: Sending verification request', $requestData);
        
        try {
            // Send verification request with headers
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'SafetyBlur-Extension/1.0',
                ])
                ->post($apiUrl, $requestData);

            \Log::info('SafetyBlur: API Response', [
                'status_code' => $response->status(),
                'body' => $response->body()
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if (isset($data['status'])) {
                    switch ($data['status']) {
                        case 'good':
                            // Store only the license key; validity is always checked live
                            $this->blueprint->dbSet('safetyblur', 'license_key', $licenseKey);
                            return redirect()->route('admin.extensions.safetyblur.index')->with('success', 'License verified successfully!');
                        
                        case 'bad':
                            // Store the key but indicate inactive via flash; index() will re-check
                            $this->blueprint->dbSet('safetyblur', 'license_key', $licenseKey);
                            return redirect()->route('admin.extensions.safetyblur.index')->with('error', 'License is inactive. Please contact support.');
                        
                        case 'invalid':
                            // Store the key entered so admin can correct it easily
                            $this->blueprint->dbSet('safetyblur', 'license_key', $licenseKey);
                            return redirect()->route('admin.extensions.safetyblur.index')->with('error', 'Invalid license key.');
                        
                        default:
                            // Unknown response
                            return redirect()->route('admin.extensions.safetyblur.index')->with('error', 'Unknown response from license server.');
                    }
                } else {
                    return redirect()->route('admin.extensions.safetyblur.index')->with('error', 'Invalid response from license server.');
                }
            } else {
                return redirect()->route('admin.extensions.safetyblur.index')->with('error', 'Failed to verify license. Please try again.');
            }
        } catch (\Exception $e) {
            return redirect()->route('admin.extensions.safetyblur.index')->with('error', 'License verification error: ' . $e->getMessage());
        }
    }

    private function getPublicIp(): string
    {
        // Cache the detected public IP briefly to minimize network calls
        return Cache::remember('safetyblur_public_ip', 300, function () {
            try {
                $resp = Http::timeout(3)->acceptJson()->get('https://api.ipify.org?format=json');
                if ($resp->successful()) {
                    $data = $resp->json();
                    if (isset($data['ip']) && is_string($data['ip'])) {
                        return $data['ip'];
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
            // Fallbacks
            return request()->server('SERVER_ADDR', gethostbyname(gethostname()));
        });
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        // Perform heartbeat check before saving
        $licenseKey = $this->blueprint->dbGet('safetyblur', 'license_key');
        
        if (!$licenseKey || !$this->performHeartbeatCheck($licenseKey)) {
            return redirect()->route('admin.extensions.safetyblur.index')
                ->with('error', 'License verification failed. Your license may have been revoked or is no longer valid.');
        }

        // Save each blur setting
        $settings = [
            'blur_dashboard_addresses',
            'blur_admin_recaptcha',
            'blur_admin_api',
            'blur_admin_databases',
            'blur_admin_users',
            'blur_admin_servers',
            'blur_admin_user_view',
        ];

        foreach ($settings as $setting) {
            $value = $request->input($setting, '0');
            $this->blueprint->dbSet('safetyblur', $setting, $value);
        }

        return redirect()->route('admin.extensions.safetyblur.index')->with('success', 'Safety Blur settings saved successfully!');
    }

    public function heartbeat(Request $request)
    {
        $licenseKey = $this->blueprint->dbGet('safetyblur', 'license_key');
        
        if (!$licenseKey) {
            return response()->json(['valid' => false]);
        }

        $isValid = $this->performHeartbeatCheck($licenseKey);
        
        return response()->json(['valid' => $isValid]);
    }
}

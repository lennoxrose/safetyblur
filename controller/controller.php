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
        $licenseKey = $this->blueprint->dbGet('safetyblur', 'license_key');
        $licenseValid = false;

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
        $verificationSecret = 'lwmmAa4/xXYtuMj6ti9dR7XICV9X52PxFfgqR1BPf2M=';
        
        $licenseConfigPath = base_path('.blueprint/extensions/safetyblur/private/license.json');
        $apiUrl = 'https://api.lennox-rose.com/v1/blueprint/safetyblur/verify';
        
        if (file_exists($licenseConfigPath)) {
            $licenseConfig = json_decode(file_get_contents($licenseConfigPath), true);
            $apiUrl = $licenseConfig['api_url'] ?? $apiUrl;
        }
        
        $controllerPath = __FILE__;
        $controllerHash = hash_file('sha256', $controllerPath);
        
        $domain = request()->getHost();
        $ownerName = $this->settings->get('settings::app:name', 'Unknown');
        $panelVersion = config('app.version', 'Unknown');
        $ipAddress = $this->getPublicIp();
        
        try {
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
                        'controller_hash' => $controllerHash,
                    ]
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                \Log::info('SafetyBlur Heartbeat Response', ['data' => $data]);
                
                if (!isset($data['status'], $data['signature'], $data['timestamp'])) {
                    \Log::error('SafetyBlur: Missing required fields in response');
                    return false;
                }
                
                $timeDiff = abs(time() - $data['timestamp']);
                if ($timeDiff > 60) {
                    \Log::error('SafetyBlur: Timestamp too old', ['diff' => $timeDiff]);
                    return false;
                }
                
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
                    \Log::error('SafetyBlur: Signature mismatch');
                    return false;
                }
                
                if ($data['status'] === 'good') {
                    \Log::info('SafetyBlur: License valid!');
                    return true;
                }
                \Log::warning('SafetyBlur: License status not good', ['status' => $data['status']]);
                return false;
            } else {
                return false;
            }
        } catch (\Exception $e) {
            return false;
        }
    }

    public function post(Request $request): RedirectResponse
    {
        if ($request->has('license_key')) {
            return $this->verifyLicense($request);
        }
        
        return $this->saveSettings($request);
    }

    public function verifyLicense(Request $request): RedirectResponse
    {
        $request->validate([
            'license_key' => 'required|string',
        ]);

        $licenseKey = $request->input('license_key');
        
        $controllerHash = hash_file('sha256', __FILE__);
        
        $licenseConfigPath = base_path('.blueprint/extensions/safetyblur/private/license.json');
        $apiUrl = 'https://api.lennox-rose.com/v1/blueprint/safetyblur/verify';
        
        if (file_exists($licenseConfigPath)) {
            $licenseConfig = json_decode(file_get_contents($licenseConfigPath), true);
            $apiUrl = $licenseConfig['api_url'] ?? $apiUrl;
        }
        
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
                            $this->blueprint->dbSet('safetyblur', 'license_key', $licenseKey);
                            return redirect()->route('admin.extensions.safetyblur.index')->with('success', 'License verified successfully!');
                        
                        case 'bad':
                            $this->blueprint->dbSet('safetyblur', 'license_key', $licenseKey);
                            return redirect()->route('admin.extensions.safetyblur.index')->with('error', 'License is inactive. Please contact support.');
                        
                        case 'invalid':
                            $this->blueprint->dbSet('safetyblur', 'license_key', $licenseKey);
                            return redirect()->route('admin.extensions.safetyblur.index')->with('error', 'Invalid license key.');
                        
                        default:
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
            return request()->server('SERVER_ADDR', gethostbyname(gethostname()));
        });
    }

    public function saveSettings(Request $request): RedirectResponse
    {
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

<!-- Safety Blur Settings Page -->

<!-- Product Header -->
<div class="row">
  <div class="col-md-12">
    <div class="box" style="background: linear-gradient(135deg, #1a1a1a 0%, #000000 100%); color: white; border: none;">
      <div class="box-body" style="padding: 30px; text-align: center;">
        <h1 style="margin: 0; font-size: 42px; font-weight: bold; color: white;">
          <i class="fa fa-shield"></i> SafetyBlur
        </h1>
        <p style="margin: 10px 0 0 0; font-size: 16px; opacity: 0.9;">
          Protect your sensitive information when screen sharing or taking screenshots
        </p>
      </div>
    </div>
  </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible">
  <button type="button" class="close" data-dismiss="alert">&times;</button>
  {{ session('success') }}
</div>
@endif

@if(session('error'))
<div class="alert alert-danger alert-dismissible">
  <button type="button" class="close" data-dismiss="alert">&times;</button>
  {{ session('error') }}
</div>
@endif

<!-- License Verification Section -->
<div class="row">
  <div class="col-md-12">
    <div class="box {{ $licenseValid ? 'box-success' : 'box-warning' }}" id="license-section">
      <div class="box-header with-border">
        <h3 class="box-title">
          <i class="fa {{ $licenseValid ? 'fa-check-circle' : 'fa-key' }}"></i> 
          License Status
        </h3>
      </div>
      <div class="box-body">
        @if($licenseValid)
          <p class="text-success">
            <i class="fa fa-check"></i> Your license is active and verified.
          </p>
        @else
          <p class="text-warning">
            <i class="fa fa-exclamation-triangle"></i> Please enter your license key to activate Safety Blur features.
          </p>
          <form method="POST" action="{{ route('admin.extensions.safetyblur.post') }}" class="form-inline" id="license-form">
            @csrf
            <div class="form-group" style="margin-right: 10px;">
              <input type="text" name="license_key" class="form-control" placeholder="Enter License Key" required style="min-width: 300px;">
            </div>
            <button type="submit" class="btn btn-primary" id="verify-btn">
              <i class="fa fa-check"></i> Verify License
            </button>
            <span id="cooldown-timer" style="margin-left: 10px; color: #f39c12; display: none;">
              Please wait <span id="countdown">5</span>s...
            </span>
          </form>
          
          <script>
            (function() {
              const form = document.getElementById('license-form');
              const btn = document.getElementById('verify-btn');
              const timer = document.getElementById('cooldown-timer');
              const countdown = document.getElementById('countdown');
              let canSubmit = true;
              
              form.addEventListener('submit', function(e) {
                if (!canSubmit) {
                  e.preventDefault();
                  return false;
                }
                
                canSubmit = false;
                btn.disabled = true;
                btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Verifying...';
                
                setTimeout(() => {
                  let timeLeft = 5;
                  timer.style.display = 'inline';
                  
                  const countdownInterval = setInterval(() => {
                    timeLeft--;
                    countdown.textContent = timeLeft;
                    
                    if (timeLeft <= 0) {
                      clearInterval(countdownInterval);
                      timer.style.display = 'none';
                      canSubmit = true;
                      btn.disabled = false;
                      btn.innerHTML = '<i class="fa fa-check"></i> Verify License';
                    }
                  }, 1000);
                }, 100);
              });
            })();
          </script>
        @endif
      </div>
    </div>
  </div>
</div>

@if(!$licenseValid)
<!-- Auto-scroll to license section if not valid -->
<script>
  document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('license-section').scrollIntoView({ behavior: 'smooth', block: 'center' });
  });
</script>
@endif

@if($licenseValid)
<!-- Settings sections only visible when licensed -->
<div class="row">
  <div class="col-md-12">
    <div class="box box-primary">
      <div class="box-header with-border">
        <h3 class="box-title">Safety Blur Settings</h3>
      </div>
      <div class="box-body">
        <p class="text-muted">
          Configure which pages should have blur protection enabled. When enabled, sensitive information will be blurred and only revealed on hover.
        </p>
      </div>
    </div>
  </div>
</div>

<form method="POST" action="{{ route('admin.extensions.safetyblur.settings') }}">
  @csrf
  
  <div class="row">
    <!-- Dashboard Blur -->
    <div class="col-md-6">
      <div class="box box-info">
        <div class="box-header with-border">
          <h3 class="box-title">Dashboard Blur</h3>
        </div>
        <div class="box-body">
          <div class="form-group">
            <label class="control-label">Server Addresses</label>
            <select name="blur_dashboard_addresses" class="form-control">
              <option value="1" {{ $blueprint->dbGet('safetyblur', 'blur_dashboard_addresses') == '1' ? 'selected' : '' }}>Enabled</option>
              <option value="0" {{ $blueprint->dbGet('safetyblur', 'blur_dashboard_addresses') == '0' ? 'selected' : '' }}>Disabled</option>
            </select>
            <p class="text-muted small">Blur server addresses on the dashboard and server view pages.</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Admin Settings Advanced -->
    <div class="col-md-6">
      <div class="box box-info">
        <div class="box-header with-border">
          <h3 class="box-title">Admin Settings</h3>
        </div>
        <div class="box-body">
          <div class="form-group">
            <label class="control-label">reCAPTCHA Keys</label>
            <select name="blur_admin_recaptcha" class="form-control">
              <option value="1" {{ $blueprint->dbGet('safetyblur', 'blur_admin_recaptcha') == '1' ? 'selected' : '' }}>Enabled</option>
              <option value="0" {{ $blueprint->dbGet('safetyblur', 'blur_admin_recaptcha') == '0' ? 'selected' : '' }}>Disabled</option>
            </select>
            <p class="text-muted small">Blur Site Key and Secret Key on /admin/settings/advanced.</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row">
    <!-- Admin API Keys -->
    <div class="col-md-6">
      <div class="box box-info">
        <div class="box-header with-border">
          <h3 class="box-title">Admin API</h3>
        </div>
        <div class="box-body">
          <div class="form-group">
            <label class="control-label">API Keys</label>
            <select name="blur_admin_api" class="form-control">
              <option value="1" {{ $blueprint->dbGet('safetyblur', 'blur_admin_api') == '1' ? 'selected' : '' }}>Enabled</option>
              <option value="0" {{ $blueprint->dbGet('safetyblur', 'blur_admin_api') == '0' ? 'selected' : '' }}>Disabled</option>
            </select>
            <p class="text-muted small">Blur API key rows on /admin/api.</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Admin Databases -->
    <div class="col-md-6">
      <div class="box box-info">
        <div class="box-header with-border">
          <h3 class="box-title">Admin Databases</h3>
        </div>
        <div class="box-body">
          <div class="form-group">
            <label class="control-label">Database Rows</label>
            <select name="blur_admin_databases" class="form-control">
              <option value="1" {{ $blueprint->dbGet('safetyblur', 'blur_admin_databases') == '1' ? 'selected' : '' }}>Enabled</option>
              <option value="0" {{ $blueprint->dbGet('safetyblur', 'blur_admin_databases') == '0' ? 'selected' : '' }}>Disabled</option>
            </select>
            <p class="text-muted small">Blur database rows on /admin/databases.</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row">
    <!-- Admin Users List -->
    <div class="col-md-6">
      <div class="box box-info">
        <div class="box-header with-border">
          <h3 class="box-title">Admin Users List</h3>
        </div>
        <div class="box-body">
          <div class="form-group">
            <label class="control-label">User Rows</label>
            <select name="blur_admin_users" class="form-control">
              <option value="1" {{ $blueprint->dbGet('safetyblur', 'blur_admin_users') == '1' ? 'selected' : '' }}>Enabled</option>
              <option value="0" {{ $blueprint->dbGet('safetyblur', 'blur_admin_users') == '0' ? 'selected' : '' }}>Disabled</option>
            </select>
            <p class="text-muted small">Blur user rows on /admin/users.</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Admin Servers -->
    <div class="col-md-6">
      <div class="box box-info">
        <div class="box-header with-border">
          <h3 class="box-title">Admin Servers</h3>
        </div>
        <div class="box-body">
          <div class="form-group">
            <label class="control-label">Server Rows</label>
            <select name="blur_admin_servers" class="form-control">
              <option value="1" {{ $blueprint->dbGet('safetyblur', 'blur_admin_servers') == '1' ? 'selected' : '' }}>Enabled</option>
              <option value="0" {{ $blueprint->dbGet('safetyblur', 'blur_admin_servers') == '0' ? 'selected' : '' }}>Disabled</option>
            </select>
            <p class="text-muted small">Blur server rows on /admin/servers.</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row">
    <!-- Admin User View -->
    <div class="col-md-6">
      <div class="box box-info">
        <div class="box-header with-border">
          <h3 class="box-title">Admin User View</h3>
        </div>
        <div class="box-body">
          <div class="form-group">
            <label class="control-label">User Identity Fields</label>
            <select name="blur_admin_user_view" class="form-control">
              <option value="1" {{ $blueprint->dbGet('safetyblur', 'blur_admin_user_view') == '1' ? 'selected' : '' }}>Enabled</option>
              <option value="0" {{ $blueprint->dbGet('safetyblur', 'blur_admin_user_view') == '0' ? 'selected' : '' }}>Disabled</option>
            </select>
            <p class="text-muted small">Blur user identity fields on /admin/users/view/*.</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row">
    <div class="col-md-12">
      <div class="box box-success">
        <div class="box-footer">
          <button type="submit" class="btn btn-primary btn-sm">Save Settings</button>
        </div>
      </div>
    </div>
  </div>
</form>
@endif

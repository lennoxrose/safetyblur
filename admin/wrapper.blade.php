{{-- Page-specific blur functionality --}}
{{-- Security: Blur IMMEDIATELY on load, verify license in background, unblur if invalid --}}
<script>
(function() {
  // STEP 1: Enable blur INSTANTLY before any async checks
  document.documentElement.setAttribute('data-safetyblur-enabled', '1');
  document.documentElement.setAttribute('data-safetyblur-initial-load', '1');
  
  function disableBlur() {
    console.log('SafetyBlur: License invalid, disabling blur');
    document.documentElement.removeAttribute('data-safetyblur-enabled');
    document.documentElement.removeAttribute('data-safetyblur-initial-load');
  }
  
  function enableBlur() { 
    console.log('SafetyBlur: License verified, blur remains enabled');
    // Remove initial-load flag to allow animations on hover
    document.documentElement.removeAttribute('data-safetyblur-initial-load');
    // Trigger all blur functions
    if (typeof blurKeyFields === 'function') blurKeyFields();
    if (typeof blurApiKeys === 'function') blurApiKeys();
    if (typeof blurDatabaseRows === 'function') blurDatabaseRows();
    if (typeof blurUserRows === 'function') blurUserRows();
    if (typeof blurServerRows === 'function') blurServerRows();
    if (typeof blurUserFormGroups === 'function') blurUserFormGroups();
  }
  
  // STEP 2: Verify license in background
  function checkNow() {
    fetch('{{ route("admin.extensions.safetyblur.heartbeat") }}', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="_token"]')?.content || ''
      }
    }).then(r => r.json()).then(d => { 
      if (d.valid) {
        enableBlur();
      } else {
        disableBlur();
      }
    }).catch(e => { 
      console.error('SafetyBlur: Heartbeat failed, assuming invalid:', e);
      disableBlur();
    });
  }
  
  // Don't run heartbeat on settings page (already validated during page render)
  // Check immediately on other admin pages, then every 5 minutes
  if (!window.location.pathname.includes('/admin/extensions/safetyblur')) {
    checkNow();
  }
  setInterval(checkNow, 300000);
})();
</script>

@if(Request::is('admin/settings/advanced') && $blueprint->dbGet('safetyblur', 'blur_admin_recaptcha') != '0')
  {{-- Blur Site Key and Secret Key fields --}}
  <style>
    /* Initial load: instant blur with no transition */
    [data-safetyblur-enabled="1"][data-safetyblur-initial-load="1"] .blur-key-field {
      filter: blur(5px) !important;
      transition: none !important;
    }
    
    /* After initial load: blur with smooth transition */
    [data-safetyblur-enabled="1"]:not([data-safetyblur-initial-load]) .blur-key-field {
      filter: blur(5px) !important;
      transition: filter 0.2s ease;
    }
    
    [data-safetyblur-enabled="1"] .blur-key-field:hover,
    [data-safetyblur-enabled="1"] .blur-key-field:focus {
      filter: blur(0px) !important;
    }
  </style>

  <script>
    function blurKeyFields() {
      if (document.documentElement.getAttribute('data-safetyblur-enabled') !== '1') return;
      const labels = document.querySelectorAll('label.control-label');
      
      labels.forEach(label => {
        const labelText = label.textContent.trim();
        
        if (labelText === 'Site Key' || labelText === 'Secret Key') {
          const parentFormGroup = label.closest('.form-group');
          if (parentFormGroup) {
            const input = parentFormGroup.querySelector('input[type="text"]');
            if (input) {
              input.classList.add('blur-key-field');
            }
          }
        }
      });
    }
    
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', blurKeyFields);
    } else {
      blurKeyFields();
    }
    
    const observer = new MutationObserver(blurKeyFields);
    observer.observe(document.body, { childList: true, subtree: true });
  </script>
@endif

@if(Request::is('admin/api') && $blueprint->dbGet('safetyblur', 'blur_admin_api') != '0')
  {{-- Blur API keys in table rows (except header row) --}}
  <style>
    /* Initial load: instant blur */
    [data-safetyblur-enabled="1"][data-safetyblur-initial-load="1"] .blur-api-row {
      filter: blur(5px) !important;
      transition: none !important;
    }
    
    /* After initial load: animated blur */
    [data-safetyblur-enabled="1"]:not([data-safetyblur-initial-load]) .blur-api-row {
      filter: blur(5px) !important;
      transition: filter 0.2s ease;
    }
    
    [data-safetyblur-enabled="1"] .blur-api-row:hover {
      filter: blur(0px) !important;
    }
  </style>

  <script>
    function blurApiKeys() {
      if (document.documentElement.getAttribute('data-safetyblur-enabled') !== '1') return;
      const tables = document.querySelectorAll('table.table');
      
      tables.forEach(table => {
        const rows = table.querySelectorAll('tr');
        
        // Skip the first row (header) and blur all data rows
        rows.forEach((row, index) => {
          if (index > 0) { // Skip first tr (header)
            row.classList.add('blur-api-row');
          }
        });
      });
    }
    
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', blurApiKeys);
    } else {
      blurApiKeys();
    }
    
    const observer = new MutationObserver(blurApiKeys);
    observer.observe(document.body, { childList: true, subtree: true });
  </script>
@endif

@if(Request::is('admin/databases') && $blueprint->dbGet('safetyblur', 'blur_admin_databases') != '0')
  {{-- Blur database passwords in table rows (except header row) --}}
  <style>
    [data-safetyblur-enabled="1"][data-safetyblur-initial-load="1"] .blur-db-row {
      filter: blur(5px) !important;
      transition: none !important;
    }
    
    [data-safetyblur-enabled="1"]:not([data-safetyblur-initial-load]) .blur-db-row {
      filter: blur(5px) !important;
      transition: filter 0.2s ease;
    }
    
    [data-safetyblur-enabled="1"] .blur-db-row:hover {
      filter: blur(0px) !important;
    }
  </style>

  <script>
    function blurDatabaseRows() {
      if (document.documentElement.getAttribute('data-safetyblur-enabled') !== '1') return;
      const tables = document.querySelectorAll('table.table');
      
      tables.forEach(table => {
        const rows = table.querySelectorAll('tr');
        
        // Skip the first row (header) and blur all data rows
        rows.forEach((row, index) => {
          if (index > 0) { // Skip first tr (header)
            row.classList.add('blur-db-row');
          }
        });
      });
    }
    
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', blurDatabaseRows);
    } else {
      blurDatabaseRows();
    }
    
    const observer = new MutationObserver(blurDatabaseRows);
    observer.observe(document.body, { childList: true, subtree: true });
  </script>
@endif

@if(Request::is('admin/users') && $blueprint->dbGet('safetyblur', 'blur_admin_users') != '0')
  {{-- Blur user rows in tbody (each tr with align-middle class) --}}
  <style>
    [data-safetyblur-enabled="1"][data-safetyblur-initial-load="1"] .blur-user-row {
      filter: blur(5px) !important;
      transition: none !important;
    }
    
    [data-safetyblur-enabled="1"]:not([data-safetyblur-initial-load]) .blur-user-row {
      filter: blur(5px) !important;
      transition: filter 0.2s ease;
    }
    
    [data-safetyblur-enabled="1"] .blur-user-row:hover {
      filter: blur(0px) !important;
    }
  </style>

  <script>
    function blurUserRows() {
      if (document.documentElement.getAttribute('data-safetyblur-enabled') !== '1') return;
      // Target tbody and find all tr.align-middle rows
      const tbody = document.querySelector('tbody');
      
      if (tbody) {
        const rows = tbody.querySelectorAll('tr.align-middle');
        
        // Blur each user row
        rows.forEach((row) => {
          row.classList.add('blur-user-row');
        });
      }
    }
    
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', blurUserRows);
    } else {
      blurUserRows();
    }
    
    const observer = new MutationObserver(blurUserRows);
    observer.observe(document.body, { childList: true, subtree: true });
  </script>
@endif

@if(Request::is('admin/servers') && $blueprint->dbGet('safetyblur', 'blur_admin_servers') != '0')
  {{-- Blur server rows (each tr with data-server attribute) --}}
  <style>
    [data-safetyblur-enabled="1"][data-safetyblur-initial-load="1"] .blur-server-row {
      filter: blur(5px) !important;
      transition: none !important;
    }
    
    [data-safetyblur-enabled="1"]:not([data-safetyblur-initial-load]) .blur-server-row {
      filter: blur(5px) !important;
      transition: filter 0.2s ease;
    }
    
    [data-safetyblur-enabled="1"] .blur-server-row:hover {
      filter: blur(0px) !important;
    }
  </style>

  <script>
    function blurServerRows() {
      if (document.documentElement.getAttribute('data-safetyblur-enabled') !== '1') return;
      // Target all tr elements with data-server attribute
      const rows = document.querySelectorAll('tr[data-server]');
      
      // Blur each server row
      rows.forEach((row) => {
        row.classList.add('blur-server-row');
      });
    }
    
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', blurServerRows);
    } else {
      blurServerRows();
    }
    
    const observer = new MutationObserver(blurServerRows);
    observer.observe(document.body, { childList: true, subtree: true });
  </script>
@endif

@if(Request::is('admin/users/view/*') && $blueprint->dbGet('safetyblur', 'blur_admin_user_view') != '0')
  {{-- Blur form-group fields in user view page (only Identity box) --}}
  <style>
    [data-safetyblur-enabled="1"][data-safetyblur-initial-load="1"] .blur-form-group {
      filter: blur(5px) !important;
      transition: none !important;
    }
    
    [data-safetyblur-enabled="1"]:not([data-safetyblur-initial-load]) .blur-form-group {
      filter: blur(5px) !important;
      transition: filter 0.2s ease;
    }
    
    [data-safetyblur-enabled="1"] .blur-form-group:hover {
      filter: blur(0px) !important;
    }
  </style>

  <script>
    function blurUserFormGroups() {
      if (document.documentElement.getAttribute('data-safetyblur-enabled') !== '1') return;
      // Find the "Identity" box by looking for the box-title
      const boxTitles = document.querySelectorAll('.box-title');
      
      boxTitles.forEach(title => {
        if (title.textContent.trim() === 'Identity') {
          // Get the parent box and find its box-body
          const box = title.closest('.box');
          if (box) {
            const boxBody = box.querySelector('.box-body');
            if (boxBody) {
              const formGroups = boxBody.querySelectorAll('.form-group');
              
              // Blur each form-group individually in Identity box only
              formGroups.forEach((formGroup) => {
                formGroup.classList.add('blur-form-group');
              });
            }
          }
        }
      });
    }
    
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', blurUserFormGroups);
    } else {
      blurUserFormGroups();
    }
    
    const observer = new MutationObserver(blurUserFormGroups);
    observer.observe(document.body, { childList: true, subtree: true });
  </script>
@endif

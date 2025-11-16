{{-- Do not trust DB for license validity; enable blur after heartbeat says valid --}}
{{-- Only run heartbeat if not on admin pages (admin wrapper handles that) --}}
@if(!Request::is('admin/*'))
<script>
(function() {
  function enableBlur() { 
    document.documentElement.setAttribute('data-safetyblur-enabled', '1');
    // Trigger blur function after enabling
    if (typeof blurAddressBlock === 'function') blurAddressBlock();
  }
  function _getCookie(name) {
    const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()\[\]\\\/\+^])/g,'\\$1') + '=([^;]*)'));
    return match ? match[1] : null;
  }

  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
    || (function(){ const c = _getCookie('XSRF-TOKEN'); return c ? decodeURIComponent(c) : ''; })();

  fetch('{{ route("admin.extensions.safetyblur.heartbeat") }}', {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken
    },
    body: JSON.stringify({})
  }).then(r => r.json()).then(d => { if (d.valid) enableBlur(); }).catch(() => {});
})();
</script>
@endif

@if($blueprint->dbGet('safetyblur', 'blur_dashboard_addresses') != '0')
<style>
  [data-safetyblur-enabled="1"] svg[data-icon="ethernet"] ~ p,
  [data-safetyblur-enabled="1"] svg[data-icon="ethernet"] + p {
    filter: blur(5px) !important;
    transition: filter 0.2s ease;
  }
  
  [data-safetyblur-enabled="1"] svg[data-icon="ethernet"] ~ p:hover,
  [data-safetyblur-enabled="1"] svg[data-icon="ethernet"] + p:hover {
    filter: blur(0px) !important;
  }
  
  [data-safetyblur-enabled="1"] div[class*="flex-1"][class*="ml-4"][class*="lg:block"][class*="lg:col-span-2"] p {
    filter: blur(5px) !important;
    transition: filter 0.2s ease;
  }
  
  [data-safetyblur-enabled="1"] div[class*="flex-1"][class*="ml-4"][class*="lg:block"][class*="lg:col-span-2"] p:hover {
    filter: blur(0px) !important;
  }
  
  /* Blur ONLY the Address stat block when viewing a server */
  [data-safetyblur-enabled="1"] .blur-address {
    filter: blur(5px) !important;
    transition: filter 0.2s ease;
  }
  
  [data-safetyblur-enabled="1"] .blur-address:hover {
    filter: blur(0px) !important;
  }
</style>

<script>
  // Add blur-address class to the Address stat block
  function blurAddressBlock() {
    if (document.documentElement.getAttribute('data-safetyblur-enabled') !== '1') return;
    const headers = document.querySelectorAll('p.font-header');
    
    headers.forEach(header => {
      // Check if the text content is "Address"
      if (header.textContent.trim() === 'Address') {

        const parent = header.closest('div.flex.flex-col');
        if (parent) {
          parent.classList.add('blur-address');
        }
      }
    });
  }
  
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', blurAddressBlock);
  } else {
    blurAddressBlock();
  }
  
  const observer = new MutationObserver(blurAddressBlock);
  observer.observe(document.body, { childList: true, subtree: true });
</script>
@endif

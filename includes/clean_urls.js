/**
 * Clean URLs Helper
 * Provides functions for managing clean URLs and session-based filters
 */

(function() {
  'use strict';

  // Store filter state in session
  function storeFilters(page, filters) {
    fetch('accounts/ajax/store_filters.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        page: page,
        filters: filters
      })
    }).catch(err => console.error('Error storing filters:', err));
  }

  // Update URL to clean format (remove query parameters)
  function updateCleanUrl() {
    if (window.history && window.history.replaceState) {
      const cleanUrl = window.location.pathname;
      if (window.location.search) {
        window.history.replaceState({}, document.title, cleanUrl);
      }
    }
  }

  // Navigate to clean URL with tab selection
  function navigateToTab(page, tab, filters) {
    if (filters) {
      storeFilters(page, filters);
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = page;
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'tab';
    input.value = tab;
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
  }

  // Initialize clean URL navigation for all data-tab links
  function initCleanUrlNavigation() {
    document.querySelectorAll('a[data-tab]').forEach(link => {
      link.addEventListener('click', function(e) {
        e.preventDefault();
        const tab = this.getAttribute('data-tab');
        const page = this.getAttribute('href') || window.location.pathname.replace(/\.php$/, '');
        const filterData = this.getAttribute('data-filter');
        
        let filters = null;
        if (filterData) {
          try {
            filters = JSON.parse(filterData);
          } catch(e) {
            console.error('Error parsing filter data:', e);
          }
        }
        
        navigateToTab(page, tab, filters);
      });
    });
  }

  // Update URL on page load
  document.addEventListener('DOMContentLoaded', function() {
    updateCleanUrl();
    initCleanUrlNavigation();
  });

  // Expose functions globally
  window.CleanUrls = {
    storeFilters: storeFilters,
    updateCleanUrl: updateCleanUrl,
    navigateToTab: navigateToTab
  };
})();


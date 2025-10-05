(function() {
  'use strict';

  console.log('[KitchenPrinter] Module loaded');

  // Wait for page to load
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  function init() {
    // Watch for invoice/quote pages and action dropdowns
    const observer = new MutationObserver(addPrintButtons);
    observer.observe(document.body, {
      childList: true,
      subtree: true
    });

    // Initial scan
    addPrintButtons();
  }

  function addPrintButtons() {
    // Look for invoice/quote action dropdowns or bulk action buttons
    addInvoiceActionButtons();
    addBulkActionButton();
  }

  function addInvoiceActionButtons() {
    // Find all action dropdown menus (adjust selectors based on actual DOM)
    const actionDropdowns = document.querySelectorAll('[data-ref="actions-dropdown"], .dropdown-menu, [role="menu"]');

    actionDropdowns.forEach(dropdown => {
      // Skip if we already added the button
      if (dropdown.querySelector('[data-kitchen-printer-button]')) {
        return;
      }

      // Check if this is an invoice or quote context
      const isInvoiceContext = window.location.pathname.includes('/invoices') ||
                               dropdown.closest('[data-entity="invoice"]') ||
                               document.querySelector('[data-entity-type="invoice"]');

      const isQuoteContext = window.location.pathname.includes('/quotes') ||
                            dropdown.closest('[data-entity="quote"]') ||
                            document.querySelector('[data-entity-type="quote"]');

      if (!isInvoiceContext && !isQuoteContext) {
        return;
      }

      // Create print button
      const printButton = document.createElement('a');
      printButton.setAttribute('data-kitchen-printer-button', 'true');
      printButton.className = dropdown.querySelector('a')?.className || 'dropdown-item';
      printButton.href = '#';
      printButton.innerHTML = '<i class="fa fa-print"></i> Print to Kitchen';

      printButton.addEventListener('click', async function(e) {
        e.preventDefault();
        e.stopPropagation();

        const entityId = getEntityIdFromContext(dropdown);
        const entityType = isQuoteContext ? 'quote' : 'invoice';

        if (entityId) {
          await printToKitchen(entityId, entityType);
        } else {
          showNotification('Could not determine invoice/quote ID', 'error');
        }
      });

      // Add to dropdown (find best insertion point)
      const separator = dropdown.querySelector('.dropdown-divider');
      if (separator) {
        separator.parentNode.insertBefore(printButton, separator);
      } else {
        dropdown.appendChild(printButton);
      }

      console.log('[KitchenPrinter] Added button to action dropdown');
    });
  }

  function addBulkActionButton() {
    // Add bulk print button to invoice/quote list pages
    const bulkActionArea = document.querySelector('[data-bulk-actions], .bulk-actions, .table-toolbar');

    if (!bulkActionArea || bulkActionArea.querySelector('[data-kitchen-printer-bulk]')) {
      return;
    }

    const isInvoicePage = window.location.pathname.includes('/invoices');
    const isQuotePage = window.location.pathname.includes('/quotes');

    if (!isInvoicePage && !isQuotePage) {
      return;
    }

    const bulkButton = document.createElement('button');
    bulkButton.setAttribute('data-kitchen-printer-bulk', 'true');
    bulkButton.className = 'btn btn-sm btn-secondary';
    bulkButton.innerHTML = '<i class="fa fa-print"></i> Print Selected to Kitchen';

    bulkButton.addEventListener('click', async function() {
      const selectedIds = getSelectedEntityIds();

      if (selectedIds.length === 0) {
        showNotification('Please select invoices to print', 'warning');
        return;
      }

      await bulkPrintToKitchen(selectedIds);
    });

    bulkActionArea.appendChild(bulkButton);
    console.log('[KitchenPrinter] Added bulk print button');
  }

  function getEntityIdFromContext(element) {
    // Try multiple methods to get the entity ID

    // Method 1: From URL
    const urlMatch = window.location.pathname.match(/\/(invoices|quotes)\/([^\/]+)/);
    if (urlMatch) {
      return urlMatch[2];
    }

    // Method 2: From data attributes
    const entityElement = element.closest('[data-entity-id], [data-id], [data-invoice-id], [data-quote-id]');
    if (entityElement) {
      return entityElement.dataset.entityId || entityElement.dataset.id ||
             entityElement.dataset.invoiceId || entityElement.dataset.quoteId;
    }

    // Method 3: From form or hidden input
    const idInput = document.querySelector('input[name="id"], input[name="invoice_id"], input[name="quote_id"]');
    if (idInput) {
      return idInput.value;
    }

    return null;
  }

  function getSelectedEntityIds() {
    // Get IDs of selected checkboxes
    const checkboxes = document.querySelectorAll('input[type="checkbox"][data-entity-id]:checked, input[type="checkbox"].entity-checkbox:checked');
    const ids = [];

    checkboxes.forEach(cb => {
      const id = cb.dataset.entityId || cb.value;
      if (id && id !== 'on') {
        ids.push(id);
      }
    });

    return ids;
  }

  async function printToKitchen(entityId, entityType = 'invoice') {
    try {
      showNotification('Sending to kitchen printer...', 'info');

      const response = await fetch(`/api/v1/${entityType}s/${entityId}/print_kitchen`, {
        method: 'POST',
        headers: {
          'X-API-TOKEN': getApiToken(),
          'X-Requested-With': 'XMLHttpRequest',
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        }
      });

      const result = await response.json();

      if (response.ok) {
        showNotification(result.message || 'Kitchen receipt sent successfully!', 'success');
      } else {
        showNotification(result.message || 'Failed to print to kitchen', 'error');
      }
    } catch (error) {
      console.error('[KitchenPrinter] Error:', error);
      showNotification('Error: ' + error.message, 'error');
    }
  }

  async function bulkPrintToKitchen(entityIds) {
    try {
      showNotification(`Sending ${entityIds.length} items to kitchen printer...`, 'info');

      const response = await fetch('/api/v1/invoices/bulk_print_kitchen', {
        method: 'POST',
        headers: {
          'X-API-TOKEN': getApiToken(),
          'X-Requested-With': 'XMLHttpRequest',
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({
          ids: entityIds
        })
      });

      const result = await response.json();

      if (response.ok) {
        showNotification(`Successfully printed ${entityIds.length} receipts to kitchen`, 'success');
      } else {
        showNotification(result.message || 'Bulk print failed', 'error');
      }
    } catch (error) {
      console.error('[KitchenPrinter] Bulk print error:', error);
      showNotification('Error: ' + error.message, 'error');
    }
  }

  function getApiToken() {
    // Try multiple methods to get API token

    // Method 1: From meta tag
    const metaToken = document.querySelector('meta[name="api-token"]');
    if (metaToken) {
      return metaToken.content;
    }

    // Method 2: From localStorage
    const localToken = localStorage.getItem('X-NINJA-TOKEN') ||
                      localStorage.getItem('api_token') ||
                      localStorage.getItem('token');
    if (localToken) {
      return localToken;
    }

    // Method 3: From cookies
    const cookies = document.cookie.split(';');
    for (const cookie of cookies) {
      const [name, value] = cookie.trim().split('=');
      if (name === 'api_token' || name === 'X-NINJA-TOKEN') {
        return value;
      }
    }

    // Method 4: From global variable (if Invoice Ninja exposes it)
    if (window.invoiceNinja && window.invoiceNinja.token) {
      return window.invoiceNinja.token;
    }

    console.warn('[KitchenPrinter] Could not find API token');
    return '';
  }

  function showNotification(message, type = 'info') {
    // Try to use Invoice Ninja's existing notification system first
    if (window.Livewire && window.Livewire.emit) {
      window.Livewire.emit('notification', { message, type });
      return;
    }

    // Fallback to custom toast notification
    const toast = document.createElement('div');
    toast.className = `kitchen-printer-toast toast-${type}`;
    toast.style.cssText = `
      position: fixed;
      top: 20px;
      right: 20px;
      padding: 15px 20px;
      background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6'};
      color: white;
      border-radius: 8px;
      box-shadow: 0 4px 6px rgba(0,0,0,0.1);
      z-index: 9999;
      max-width: 300px;
      animation: slideIn 0.3s ease-out;
    `;
    toast.textContent = message;

    // Add animation
    const style = document.createElement('style');
    style.textContent = `
      @keyframes slideIn {
        from { transform: translateX(400px); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
      }
      @keyframes slideOut {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(400px); opacity: 0; }
      }
    `;
    document.head.appendChild(style);

    document.body.appendChild(toast);

    // Auto-remove after 4 seconds
    setTimeout(() => {
      toast.style.animation = 'slideOut 0.3s ease-in';
      setTimeout(() => toast.remove(), 300);
    }, 4000);

    console.log(`[KitchenPrinter] ${type.toUpperCase()}: ${message}`);
  }
})();

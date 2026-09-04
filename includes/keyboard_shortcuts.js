/**
 * Global Keyboard Shortcuts System
 * Provides keyboard shortcuts for common actions across the application
 */

class KeyboardShortcuts {
    constructor() {
        this.shortcuts = new Map();
        this.helpModal = null;
        this.isHelpModalVisible = false;
        this.init();
    }

    init() {
        // Register default shortcuts
        this.registerDefaultShortcuts();
        
        // Add event listeners
        document.addEventListener('keydown', this.handleKeyDown.bind(this));
        
        // Create help modal
        this.createHelpModal();
        
        // Add help button to navigation if it doesn't exist
        this.addHelpButton();
    }

    registerDefaultShortcuts() {
        // Global shortcuts (work on all pages)
        this.register('ctrl+/', 'Show keyboard shortcuts help', () => this.showHelp());
        this.register('escape', 'Close modals and clear selections', () => this.handleEscape());
        this.register('ctrl+s', 'Save current form', () => this.saveCurrentForm());
        this.register('ctrl+f', 'Focus search input', () => this.focusSearch());
        this.register('ctrl+n', 'Create new item', () => this.createNewItem());
        this.register('ctrl+e', 'Edit current item', () => this.editCurrentItem());
        this.register('ctrl+p', 'Print current page', () => this.printCurrentPage());
        this.register('ctrl+shift+e', 'Export data', () => this.exportData());
        this.register('ctrl+shift+f', 'Show advanced filters', () => this.showAdvancedFilters());
        this.register('ctrl+shift+s', 'Save current filter', () => this.saveCurrentFilter());
        this.register('ctrl+shift+h', 'Show search history', () => this.showSearchHistory());
        this.register('ctrl+shift+b', 'Show saved filters', () => this.showSavedFilters());
        
        // Navigation shortcuts
        this.register('alt+1', 'Go to Dashboard', () => this.navigateToTab('dashboard'));
        this.register('alt+2', 'Go to Invoices', () => this.navigateToTab('invoices'));
        this.register('alt+3', 'Go to Payments', () => this.navigateToTab('payments'));
        this.register('alt+4', 'Go to Expenses', () => this.navigateToTab('expenses'));
        this.register('alt+5', 'Go to Statements', () => this.navigateToTab('statements'));
        this.register('alt+6', 'Go to Reports', () => this.navigateToTab('reports'));
        
        // Table navigation shortcuts
        this.register('arrowup', 'Navigate table up', () => this.navigateTable('up'));
        this.register('arrowdown', 'Navigate table down', () => this.navigateTable('down'));
        this.register('home', 'Go to first table row', () => this.navigateTable('first'));
        this.register('end', 'Go to last table row', () => this.navigateTable('last'));
        this.register('enter', 'Open selected item', () => this.openSelectedItem());
        this.register('delete', 'Delete selected item', () => this.deleteSelectedItem());
        
        // Bulk operations shortcuts
        this.register('ctrl+a', 'Select all items', () => this.selectAllItems());
        this.register('ctrl+shift+a', 'Clear selection', () => this.clearSelection());
        this.register('ctrl+shift+m', 'Bulk email', () => this.bulkEmail());
        this.register('ctrl+shift+v', 'Bulk void', () => this.bulkVoid());
        this.register('ctrl+shift+d', 'Bulk download PDFs', () => this.bulkDownloadPDFs());
        this.register('ctrl+shift+c', 'Bulk export CSV', () => this.bulkExportCSV());
        
        // Form shortcuts
        this.register('ctrl+enter', 'Submit form', () => this.submitForm());
        this.register('ctrl+shift+r', 'Reset form', () => this.resetForm());
        this.register('tab', 'Next form field', () => this.nextFormField());
        this.register('shift+tab', 'Previous form field', () => this.previousFormField());
        
        // Search and filter shortcuts
        this.register('ctrl+shift+q', 'Quick search', () => this.quickSearch());
        this.register('ctrl+shift+o', 'Overdue filter', () => this.applyOverdueFilter());
        this.register('ctrl+shift+t', 'This month filter', () => this.applyThisMonthFilter());
        this.register('ctrl+shift+l', 'Last 30 days filter', () => this.applyLast30DaysFilter());
        this.register('ctrl+shift+h', 'High value filter', () => this.applyHighValueFilter());
    }

    register(keyCombo, description, action, context = 'global') {
        const normalizedKey = this.normalizeKeyCombo(keyCombo);
        this.shortcuts.set(normalizedKey, {
            keyCombo,
            description,
            action,
            context
        });
    }

    normalizeKeyCombo(keyCombo) {
        return keyCombo.toLowerCase()
            .replace(/\s+/g, '')
            .replace('ctrl', 'control')
            .replace('cmd', 'meta')
            .replace('alt', 'alt')
            .replace('shift', 'shift');
    }

    handleKeyDown(event) {
        // Don't handle shortcuts when typing in inputs, textareas, or contenteditable elements
        if (this.isInputElement(event.target)) {
            return;
        }

        const keyCombo = this.buildKeyCombo(event);
        const shortcut = this.shortcuts.get(keyCombo);

        if (shortcut) {
            event.preventDefault();
            event.stopPropagation();
            
            try {
                shortcut.action();
            } catch (error) {
                console.error('Error executing keyboard shortcut:', error);
                this.showNotification('Error executing shortcut: ' + error.message, 'error');
            }
        }
    }

    buildKeyCombo(event) {
        const parts = [];
        
        if (event.ctrlKey) parts.push('ctrl');
        if (event.metaKey) parts.push('cmd');
        if (event.altKey) parts.push('alt');
        if (event.shiftKey) parts.push('shift');
        
        const key = event.key.toLowerCase();
        if (key !== 'control' && key !== 'meta' && key !== 'alt' && key !== 'shift') {
            parts.push(key);
        }
        
        return parts.join('+');
    }

    isInputElement(element) {
        const tagName = element.tagName.toLowerCase();
        const inputTypes = ['input', 'textarea', 'select'];
        const contentEditable = element.contentEditable === 'true';
        
        return inputTypes.includes(tagName) || contentEditable;
    }

    // Action implementations
    showHelp() {
        if (this.isHelpModalVisible) {
            this.hideHelp();
        } else {
            this.showHelpModal();
        }
    }

    handleEscape() {
        // Close any open modals
        const modals = document.querySelectorAll('.modal.show');
        modals.forEach(modal => {
            const bsModal = bootstrap.Modal.getInstance(modal);
            if (bsModal) {
                bsModal.hide();
            }
        });

        // Clear selections
        this.clearSelection();
        
        // Close dropdowns
        const dropdowns = document.querySelectorAll('.dropdown-menu.show');
        dropdowns.forEach(dropdown => {
            dropdown.classList.remove('show');
        });
    }

    saveCurrentForm() {
        const form = document.querySelector('form:not([data-no-save])');
        if (form) {
            const submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
            if (submitBtn) {
                submitBtn.click();
            } else {
                this.showNotification('No submit button found', 'warning');
            }
        } else {
            this.showNotification('No form to save', 'warning');
        }
    }

    focusSearch() {
        const searchInput = document.querySelector('#searchInput, input[placeholder*="search" i], input[placeholder*="Search" i]');
        if (searchInput) {
            searchInput.focus();
            searchInput.select();
        } else {
            this.showNotification('No search input found', 'warning');
        }
    }

    createNewItem() {
        const createBtn = document.querySelector('a[href*="create"], a[href*="add"], button[onclick*="create"], button[onclick*="add"]');
        if (createBtn) {
            createBtn.click();
        } else {
            this.showNotification('No create button found', 'warning');
        }
    }

    editCurrentItem() {
        const editBtn = document.querySelector('a[href*="edit"], button[onclick*="edit"]');
        if (editBtn) {
            editBtn.click();
        } else {
            this.showNotification('No edit button found', 'warning');
        }
    }

    printCurrentPage() {
        window.print();
    }

    exportData() {
        const exportBtn = document.querySelector('button[onclick*="export"], a[href*="export"]');
        if (exportBtn) {
            exportBtn.click();
        } else {
            this.showNotification('No export option found', 'warning');
        }
    }

    showAdvancedFilters() {
        const filterBtn = document.querySelector('#showAdvancedFiltersBtn, button[data-bs-target="#advancedFilters"]');
        if (filterBtn) {
            filterBtn.click();
        } else {
            this.showNotification('No advanced filters found', 'warning');
        }
    }

    saveCurrentFilter() {
        const saveBtn = document.querySelector('#saveFilterBtn, button[onclick*="save"]');
        if (saveBtn) {
            saveBtn.click();
        } else {
            this.showNotification('No save filter option found', 'warning');
        }
    }

    showSearchHistory() {
        const historyBtn = document.querySelector('#searchHistoryBtn, button[onclick*="history"]');
        if (historyBtn) {
            historyBtn.click();
        } else {
            this.showNotification('No search history found', 'warning');
        }
    }

    showSavedFilters() {
        const filtersBtn = document.querySelector('#savedFiltersBtn, button[onclick*="filters"]');
        if (filtersBtn) {
            filtersBtn.click();
        } else {
            this.showNotification('No saved filters found', 'warning');
        }
    }

    navigateToTab(tabName) {
        const tabLink = document.querySelector(`a[href*="tab=${tabName}"], a[data-tab="${tabName}"]`);
        if (tabLink) {
            tabLink.click();
        } else {
            // Try to navigate to the main account page with the tab
            window.location.href = `account.php?tab=${tabName}`;
        }
    }

    navigateTable(direction) {
        const table = document.querySelector('table tbody');
        if (!table) return;

        const rows = Array.from(table.querySelectorAll('tr'));
        const currentRow = document.querySelector('tr.table-active, tr.selected');
        let currentIndex = currentRow ? rows.indexOf(currentRow) : -1;

        // Remove existing selection
        rows.forEach(row => row.classList.remove('table-active', 'selected'));

        switch (direction) {
            case 'up':
                currentIndex = Math.max(0, currentIndex - 1);
                break;
            case 'down':
                currentIndex = Math.min(rows.length - 1, currentIndex + 1);
                break;
            case 'first':
                currentIndex = 0;
                break;
            case 'last':
                currentIndex = rows.length - 1;
                break;
        }

        if (rows[currentIndex]) {
            rows[currentIndex].classList.add('table-active');
            rows[currentIndex].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    openSelectedItem() {
        const selectedRow = document.querySelector('tr.table-active, tr.selected');
        if (selectedRow) {
            const link = selectedRow.querySelector('a[href*="view"], a[href*="edit"]');
            if (link) {
                link.click();
            }
        }
    }

    deleteSelectedItem() {
        const selectedRow = document.querySelector('tr.table-active, tr.selected');
        if (selectedRow) {
            const deleteBtn = selectedRow.querySelector('button[onclick*="delete"], a[href*="delete"]');
            if (deleteBtn) {
                deleteBtn.click();
            }
        }
    }

    selectAllItems() {
        const selectAllCheckbox = document.querySelector('#selectAll, input[type="checkbox"][onchange*="toggleAll"]');
        if (selectAllCheckbox) {
            selectAllCheckbox.checked = true;
            selectAllCheckbox.dispatchEvent(new Event('change'));
        }
    }

    clearSelection() {
        const selectAllCheckbox = document.querySelector('#selectAll, input[type="checkbox"][onchange*="toggleAll"]');
        if (selectAllCheckbox) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.dispatchEvent(new Event('change'));
        }

        // Clear individual checkboxes
        const checkboxes = document.querySelectorAll('input[type="checkbox"]:checked');
        checkboxes.forEach(checkbox => {
            if (checkbox.id !== 'selectAll') {
                checkbox.checked = false;
                checkbox.dispatchEvent(new Event('change'));
            }
        });
    }

    bulkEmail() {
        const emailBtn = document.querySelector('button[onclick*="bulkEmail"], button[onclick*="showBulkEmail"]');
        if (emailBtn) {
            emailBtn.click();
        }
    }

    bulkVoid() {
        const voidBtn = document.querySelector('button[onclick*="void"], button[onclick*="bulkVoid"]');
        if (voidBtn) {
            voidBtn.click();
        }
    }

    bulkDownloadPDFs() {
        const pdfBtn = document.querySelector('button[onclick*="pdf"], button[onclick*="downloadPDF"]');
        if (pdfBtn) {
            pdfBtn.click();
        }
    }

    bulkExportCSV() {
        const csvBtn = document.querySelector('button[onclick*="csv"], button[onclick*="exportCSV"]');
        if (csvBtn) {
            csvBtn.click();
        }
    }

    submitForm() {
        const form = document.querySelector('form:not([data-no-submit])');
        if (form) {
            form.submit();
        }
    }

    resetForm() {
        const form = document.querySelector('form');
        if (form) {
            form.reset();
        }
    }

    nextFormField() {
        const focusableElements = this.getFocusableElements();
        const currentIndex = focusableElements.indexOf(document.activeElement);
        if (currentIndex < focusableElements.length - 1) {
            focusableElements[currentIndex + 1].focus();
        }
    }

    previousFormField() {
        const focusableElements = this.getFocusableElements();
        const currentIndex = focusableElements.indexOf(document.activeElement);
        if (currentIndex > 0) {
            focusableElements[currentIndex - 1].focus();
        }
    }

    getFocusableElements() {
        const selector = 'input, textarea, select, button, a[href], [tabindex]:not([tabindex="-1"])';
        return Array.from(document.querySelectorAll(selector))
            .filter(el => !el.disabled && !el.hidden);
    }

    quickSearch() {
        this.focusSearch();
    }

    applyOverdueFilter() {
        const overdueBtn = document.querySelector('button[data-filter="overdue"]');
        if (overdueBtn) {
            overdueBtn.click();
        }
    }

    applyThisMonthFilter() {
        const thisMonthBtn = document.querySelector('button[data-filter="this_month"]');
        if (thisMonthBtn) {
            thisMonthBtn.click();
        }
    }

    applyLast30DaysFilter() {
        const last30Btn = document.querySelector('button[data-filter="last_30_days"]');
        if (last30Btn) {
            last30Btn.click();
        }
    }

    applyHighValueFilter() {
        const highValueBtn = document.querySelector('button[data-filter="high_value"]');
        if (highValueBtn) {
            highValueBtn.click();
        }
    }

    createHelpModal() {
        const modalHtml = `
            <div class="modal fade" id="keyboardShortcutsModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-keyboard me-2"></i>Keyboard Shortcuts
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div id="shortcutsList">
                                <!-- Shortcuts will be populated here -->
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);
        this.helpModal = new bootstrap.Modal(document.getElementById('keyboardShortcutsModal'));
        
        // Populate shortcuts list
        this.populateShortcutsList();
    }

    populateShortcutsList() {
        const shortcutsList = document.getElementById('shortcutsList');
        if (!shortcutsList) return;

        // Group shortcuts by category
        const categories = {
            'Navigation': [],
            'Search & Filters': [],
            'Table Operations': [],
            'Bulk Operations': [],
            'Form Operations': [],
            'General': []
        };

        this.shortcuts.forEach(shortcut => {
            const keyCombo = shortcut.keyCombo;
            const description = shortcut.description;
            
            if (keyCombo.includes('alt+')) {
                categories['Navigation'].push({ keyCombo, description });
            } else if (keyCombo.includes('search') || keyCombo.includes('filter')) {
                categories['Search & Filters'].push({ keyCombo, description });
            } else if (keyCombo.includes('arrow') || keyCombo.includes('table')) {
                categories['Table Operations'].push({ keyCombo, description });
            } else if (keyCombo.includes('bulk') || keyCombo.includes('select')) {
                categories['Bulk Operations'].push({ keyCombo, description });
            } else if (keyCombo.includes('form') || keyCombo.includes('submit')) {
                categories['Form Operations'].push({ keyCombo, description });
            } else {
                categories['General'].push({ keyCombo, description });
            }
        });

        let html = '';
        Object.keys(categories).forEach(categoryName => {
            const shortcuts = categories[categoryName];
            if (shortcuts.length > 0) {
                html += `
                    <div class="mb-4">
                        <h6 class="text-primary mb-3">${categoryName}</h6>
                        <div class="row g-2">
                `;
                
                shortcuts.forEach(shortcut => {
                    html += `
                        <div class="col-md-6">
                            <div class="d-flex justify-content-between align-items-center p-2 border rounded">
                                <span class="text-muted">${shortcut.description}</span>
                                <kbd class="bg-dark text-white">${shortcut.keyCombo}</kbd>
                            </div>
                        </div>
                    `;
                });
                
                html += `
                        </div>
                    </div>
                `;
            }
        });

        shortcutsList.innerHTML = html;
    }

    showHelpModal() {
        this.populateShortcutsList();
        this.helpModal.show();
        this.isHelpModalVisible = true;
    }

    hideHelp() {
        this.helpModal.hide();
        this.isHelpModalVisible = false;
    }

    addHelpButton() {
        // Add help button to navigation if it doesn't exist
        const nav = document.querySelector('.navbar-nav, .nav-tabs');
        if (nav && !document.querySelector('#keyboardHelpBtn')) {
            const helpBtn = document.createElement('button');
            helpBtn.id = 'keyboardHelpBtn';
            helpBtn.className = 'btn btn-outline-info btn-sm ms-2';
            helpBtn.innerHTML = '<i class="bi bi-keyboard"></i> Shortcuts';
            helpBtn.onclick = () => this.showHelpModal();
            nav.appendChild(helpBtn);
        }
    }

    showNotification(message, type = 'info') {
        // Create a simple notification
        const notification = document.createElement('div');
        notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
        notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        notification.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(notification);
        
        // Auto-remove after 3 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 3000);
    }
}

// Initialize keyboard shortcuts when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    window.keyboardShortcuts = new KeyboardShortcuts();
});

// Export for use in other scripts
window.KeyboardShortcuts = KeyboardShortcuts;

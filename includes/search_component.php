<?php
/**
 * Universal Search Component
 * Provides a reusable search interface with saved filters and search history
 */

function renderSearchComponent($page_type, $current_filters = [], $search_query = '') {
    ?>
    <div class="advanced-search-container mb-4">
        <!-- Search Bar -->
        <div class="search-bar-container mb-3">
            <div class="input-group">
                <input type="text" 
                       class="form-control" 
                       id="searchInput" 
                       placeholder="Search <?= ucfirst($page_type) ?>..." 
                       value="<?= h($search_query) ?>"
                       autocomplete="off">
                <button class="btn btn-outline-secondary" type="button" id="searchHistoryBtn" title="Search History">
                    <i class="bi bi-clock-history"></i>
                </button>
                <button class="btn btn-outline-secondary" type="button" id="savedFiltersBtn" title="Saved Filters">
                    <i class="bi bi-bookmark"></i>
                </button>
                <button class="btn btn-primary" type="button" id="searchBtn">
                    <i class="bi bi-search"></i> Search
                </button>
                <button class="btn btn-outline-danger" type="button" id="clearSearchBtn" title="Clear Search">
                    <i class="bi bi-x-circle"></i>
                </button>
            </div>
            
            <!-- Search Suggestions Dropdown -->
            <div id="searchSuggestions" class="search-suggestions dropdown-menu" style="display: none;">
                <!-- Suggestions will be loaded here -->
            </div>
        </div>

        <!-- Advanced Filters Panel -->
        <div class="advanced-filters-panel" id="advancedFiltersPanel" style="display: none;">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">Advanced Filters</h6>
                    <div>
                        <button class="btn btn-sm btn-outline-primary me-2" id="saveFilterBtn">
                            <i class="bi bi-bookmark-plus"></i> Save Filter
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" id="toggleFiltersBtn">
                            <i class="bi bi-chevron-up"></i> Collapse
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php if ($page_type === 'invoices'): ?>
                            <!-- Invoice-specific filters -->
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status[]" multiple>
                                    <?php 
                                    $status_value = $current_filters['status'] ?? [];
                                    if (is_array($status_value)) {
                                        $status_array = $status_value;
                                    } else {
                                        $status_array = empty($status_value) ? [] : [$status_value];
                                    }
                                    ?>
                                    <option value="draft" <?= in_array('draft', $status_array) ? 'selected' : '' ?>>Draft</option>
                                    <option value="issued" <?= in_array('issued', $status_array) ? 'selected' : '' ?>>Issued</option>
                                    <option value="partially_paid" <?= in_array('partially_paid', $status_array) ? 'selected' : '' ?>>Partially Paid</option>
                                    <option value="paid" <?= in_array('paid', $status_array) ? 'selected' : '' ?>>Paid</option>
                                    <option value="void" <?= in_array('void', $status_array) ? 'selected' : '' ?>>Void</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Date From</label>
                                <input type="date" class="form-control" name="date_from" value="<?= h($current_filters['date_from'] ?? '') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Date To</label>
                                <input type="date" class="form-control" name="date_to" value="<?= h($current_filters['date_to'] ?? '') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Amount Range</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="amount_min" placeholder="Min" value="<?= h($current_filters['amount_min'] ?? '') ?>">
                                    <span class="input-group-text">-</span>
                                    <input type="number" class="form-control" name="amount_max" placeholder="Max" value="<?= h($current_filters['amount_max'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="overdue" id="overdue" <?= !empty($current_filters['overdue']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="overdue">
                                        Overdue Only
                                    </label>
                                </div>
                            </div>
                            
                        <?php elseif ($page_type === 'payments'): ?>
                            <!-- Payment-specific filters -->
                            <?php
                            // Normalize payment_method to always be an array
                            $payment_method_value = $current_filters['payment_method'] ?? [];
                            if (!is_array($payment_method_value)) {
                                $payment_method_value = empty($payment_method_value) ? [] : [$payment_method_value];
                            }
                            ?>
                            <div class="col-md-3">
                                <label class="form-label">Payment Method</label>
                                <select class="form-select" name="payment_method[]" multiple>
                                    <option value="cash" <?= in_array('cash', $payment_method_value) ? 'selected' : '' ?>>Cash</option>
                                    <option value="card" <?= in_array('card', $payment_method_value) ? 'selected' : '' ?>>Card</option>
                                    <option value="bank" <?= in_array('bank', $payment_method_value) ? 'selected' : '' ?>>Bank Transfer</option>
                                    <option value="cheque" <?= in_array('cheque', $payment_method_value) ? 'selected' : '' ?>>Cheque</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Date From</label>
                                <input type="date" class="form-control" name="date_from" value="<?= h($current_filters['date_from'] ?? '') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Date To</label>
                                <input type="date" class="form-control" name="date_to" value="<?= h($current_filters['date_to'] ?? '') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Amount Range</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="amount_min" placeholder="Min" value="<?= h($current_filters['amount_min'] ?? '') ?>">
                                    <span class="input-group-text">-</span>
                                    <input type="number" class="form-control" name="amount_max" placeholder="Max" value="<?= h($current_filters['amount_max'] ?? '') ?>">
                                </div>
                            </div>
                            
                        <?php elseif ($page_type === 'expenses'): ?>
                            <!-- Expense-specific filters -->
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status[]" multiple>
                                    <option value="pending" <?= in_array('pending', $current_filters['status'] ?? []) ? 'selected' : '' ?>>Pending</option>
                                    <option value="posted" <?= in_array('posted', $current_filters['status'] ?? []) ? 'selected' : '' ?>>Posted</option>
                                    <option value="void" <?= in_array('void', $current_filters['status'] ?? []) ? 'selected' : '' ?>>Void</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Date From</label>
                                <input type="date" class="form-control" name="date_from" value="<?= h($current_filters['date_from'] ?? '') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Date To</label>
                                <input type="date" class="form-control" name="date_to" value="<?= h($current_filters['date_to'] ?? '') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Amount Range</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="amount_min" placeholder="Min" value="<?= h($current_filters['amount_min'] ?? '') ?>">
                                    <span class="input-group-text">-</span>
                                    <input type="number" class="form-control" name="amount_max" placeholder="Max" value="<?= h($current_filters['amount_max'] ?? '') ?>">
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-12">
                            <button class="btn btn-primary" id="applyFiltersBtn">
                                <i class="bi bi-funnel"></i> Apply Filters
                            </button>
                            <button class="btn btn-outline-secondary" id="resetFiltersBtn">
                                <i class="bi bi-arrow-clockwise"></i> Reset
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Filter Buttons -->
        <div class="quick-filters mb-3" id="quickFilters">
            <button class="btn btn-sm btn-outline-primary me-2" data-filter="overdue">
                <i class="bi bi-exclamation-triangle"></i> Overdue
            </button>
            <button class="btn btn-sm btn-outline-primary me-2" data-filter="this_month">
                <i class="bi bi-calendar-month"></i> This Month
            </button>
            <button class="btn btn-sm btn-outline-primary me-2" data-filter="last_30_days">
                <i class="bi bi-calendar-date"></i> Last 30 Days
            </button>
            <button class="btn btn-sm btn-outline-primary me-2" data-filter="high_value">
                <i class="bi bi-currency-dollar"></i> High Value
            </button>
            <button class="btn btn-sm btn-outline-secondary" id="showAdvancedFiltersBtn">
                <i class="bi bi-gear"></i> Advanced Filters
            </button>
        </div>
    </div>

    <!-- Saved Filters Modal -->
    <div class="modal fade" id="savedFiltersModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Saved Filters</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="savedFiltersList">
                        <div class="text-center">
                            <div class="spinner-border" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Search History Modal -->
    <div class="modal fade" id="searchHistoryModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Search History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="searchHistoryList">
                        <div class="text-center">
                            <div class="spinner-border" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Save Filter Modal -->
    <div class="modal fade" id="saveFilterModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Save Filter</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="saveFilterForm">
                        <div class="mb-3">
                            <label class="form-label">Filter Name</label>
                            <input type="text" class="form-control" id="filterName" required>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="isGlobal">
                                <label class="form-check-label" for="isGlobal">
                                    Make available to all users
                                </label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="isDefault">
                                <label class="form-check-label" for="isDefault">
                                    Set as default filter for this page
                                </label>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmSaveFilter">Save Filter</button>
                </div>
            </div>
        </div>
    </div>

    <style>
    .search-suggestions {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        z-index: 1000;
        max-height: 300px;
        overflow-y: auto;
        border: 1px solid #dee2e6;
        border-radius: 0.375rem;
        background: white;
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
    }

    .search-suggestion-item {
        padding: 0.5rem 1rem;
        cursor: pointer;
        border-bottom: 1px solid #f8f9fa;
    }

    .search-suggestion-item:hover {
        background-color: #f8f9fa;
    }

    .search-suggestion-item:last-child {
        border-bottom: none;
    }

    .suggestion-type {
        font-size: 0.75rem;
        color: #6c757d;
        text-transform: uppercase;
        font-weight: 600;
    }

    .quick-filters .btn {
        margin-bottom: 0.25rem;
    }

    .advanced-filters-panel {
        animation: slideDown 0.3s ease-out;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .filter-tag {
        display: inline-block;
        background: #e9ecef;
        color: #495057;
        padding: 0.25rem 0.5rem;
        border-radius: 0.25rem;
        font-size: 0.875rem;
        margin: 0.125rem;
    }

    .filter-tag .remove {
        margin-left: 0.5rem;
        cursor: pointer;
        color: #dc3545;
    }
    </style>

    <script>
    // Search component JavaScript
    document.addEventListener('DOMContentLoaded', function() {
        const pageType = '<?= $page_type ?>';
        const searchInput = document.getElementById('searchInput');
        const searchBtn = document.getElementById('searchBtn');
        const clearSearchBtn = document.getElementById('clearSearchBtn');
        const showAdvancedFiltersBtn = document.getElementById('showAdvancedFiltersBtn');
        const toggleFiltersBtn = document.getElementById('toggleFiltersBtn');
        const advancedFiltersPanel = document.getElementById('advancedFiltersPanel');
        const searchSuggestions = document.getElementById('searchSuggestions');
        const savedFiltersBtn = document.getElementById('savedFiltersBtn');
        const searchHistoryBtn = document.getElementById('searchHistoryBtn');
        const saveFilterBtn = document.getElementById('saveFilterBtn');
        const applyFiltersBtn = document.getElementById('applyFiltersBtn');
        const resetFiltersBtn = document.getElementById('resetFiltersBtn');
        const quickFilters = document.querySelectorAll('.quick-filters .btn[data-filter]');

        // Search input with suggestions
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            
            if (query.length >= 2) {
                searchTimeout = setTimeout(() => {
                    loadSearchSuggestions(query);
                }, 300);
            } else {
                searchSuggestions.style.display = 'none';
            }
        });

        // Hide suggestions when clicking outside
        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !searchSuggestions.contains(e.target)) {
                searchSuggestions.style.display = 'none';
            }
        });

        // Search functionality
        searchBtn.addEventListener('click', performSearch);
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                performSearch();
            }
        });

        // Clear search
        clearSearchBtn.addEventListener('click', function() {
            searchInput.value = '';
            searchSuggestions.style.display = 'none';
            clearAllFilters();
            performSearch();
        });

        // Toggle advanced filters
        showAdvancedFiltersBtn.addEventListener('click', function() {
            advancedFiltersPanel.style.display = 'block';
            this.style.display = 'none';
        });

        toggleFiltersBtn.addEventListener('click', function() {
            advancedFiltersPanel.style.display = 'none';
            showAdvancedFiltersBtn.style.display = 'inline-block';
        });

        // Quick filters
        quickFilters.forEach(btn => {
            btn.addEventListener('click', function() {
                const filterType = this.dataset.filter;
                applyQuickFilter(filterType);
            });
        });

        // Saved filters
        savedFiltersBtn.addEventListener('click', function() {
            new bootstrap.Modal(document.getElementById('savedFiltersModal')).show();
            loadSavedFilters();
        });

        // Search history
        searchHistoryBtn.addEventListener('click', function() {
            new bootstrap.Modal(document.getElementById('searchHistoryModal')).show();
            loadSearchHistory();
        });

        // Save filter
        saveFilterBtn.addEventListener('click', function() {
            new bootstrap.Modal(document.getElementById('saveFilterModal')).show();
        });

        document.getElementById('confirmSaveFilter').addEventListener('click', function() {
            saveCurrentFilter();
        });

        // Apply filters
        applyFiltersBtn.addEventListener('click', function() {
            performSearch();
        });

        // Reset filters
        resetFiltersBtn.addEventListener('click', function() {
            clearAllFilters();
            performSearch();
        });

        function loadSearchSuggestions(query) {
            fetch(`ajax/get_search_suggestions.php?page_type=${pageType}&query=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.suggestions.length > 0) {
                        displaySuggestions(data.suggestions);
                    } else {
                        searchSuggestions.style.display = 'none';
                    }
                })
                .catch(error => {
                    console.error('Error loading suggestions:', error);
                    searchSuggestions.style.display = 'none';
                });
        }

        function displaySuggestions(suggestions) {
            let html = '';
            suggestions.forEach(suggestion => {
                html += `
                    <div class="search-suggestion-item" onclick="selectSuggestion('${suggestion.suggestion_text}')">
                        <div>${suggestion.suggestion_text}</div>
                        <div class="suggestion-type">${suggestion.suggestion_type}</div>
                    </div>
                `;
            });
            searchSuggestions.innerHTML = html;
            searchSuggestions.style.display = 'block';
        }

        function selectSuggestion(text) {
            searchInput.value = text;
            searchSuggestions.style.display = 'none';
            performSearch();
        }

        function performSearch() {
            const searchQuery = searchInput.value.trim();
            const filters = getCurrentFilters();
            
            // Record search
            recordSearch(searchQuery, filters);
            
            // Trigger search event for parent page
            const searchEvent = new CustomEvent('advancedSearch', {
                detail: {
                    query: searchQuery,
                    filters: filters
                }
            });
            document.dispatchEvent(searchEvent);
        }

        function getCurrentFilters() {
            const filters = {};
            const form = advancedFiltersPanel.querySelector('form') || advancedFiltersPanel;
            
            // Get all form inputs
            const inputs = form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                if (input.name) {
                    const filterName = input.name.endsWith('[]') ? input.name.slice(0, -2) : input.name;
                    if (input.type === 'checkbox') {
                        if (input.checked) {
                            filters[filterName] = true;
                        }
                    } else if (input.type === 'radio') {
                        if (input.checked) {
                            filters[filterName] = input.value;
                        }
                    } else if (input.type === 'number' || input.type === 'date') {
                        if (input.value) {
                            filters[filterName] = input.value;
                        }
                    } else if (input.tagName === 'SELECT' && input.multiple) {
                        const selected = Array.from(input.selectedOptions).map(option => option.value);
                        if (selected.length > 0) {
                            filters[filterName] = selected;
                        }
                    } else if (input.value) {
                        filters[filterName] = input.value;
                    }
                }
            });
            
            return filters;
        }

        function applyQuickFilter(filterType) {
            clearAllFilters();
            
            switch (filterType) {
                case 'overdue':
                    if (pageType === 'invoices') {
                        document.querySelector('input[name="overdue"]').checked = true;
                    }
                    break;
                case 'this_month':
                    const today = new Date();
                    const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
                    const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                    document.querySelector('input[name="date_from"]').value = firstDay.toISOString().split('T')[0];
                    document.querySelector('input[name="date_to"]').value = lastDay.toISOString().split('T')[0];
                    break;
                case 'last_30_days':
                    const thirtyDaysAgo = new Date();
                    thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
                    document.querySelector('input[name="date_from"]').value = thirtyDaysAgo.toISOString().split('T')[0];
                    document.querySelector('input[name="date_to"]').value = new Date().toISOString().split('T')[0];
                    break;
                case 'high_value':
                    document.querySelector('input[name="amount_min"]').value = '10000';
                    break;
            }
            
            performSearch();
        }

        function clearAllFilters() {
            const form = advancedFiltersPanel.querySelector('form') || advancedFiltersPanel;
            const inputs = form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                if (input.type === 'checkbox' || input.type === 'radio') {
                    input.checked = false;
                } else {
                    input.value = '';
                }
            });
        }

        function loadSavedFilters() {
            fetch(`ajax/get_saved_filters.php?page_type=${pageType}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displaySavedFilters(data.filters);
                    } else {
                        document.getElementById('savedFiltersList').innerHTML = 
                            '<div class="alert alert-danger">Error loading filters: ' + data.error + '</div>';
                    }
                })
                .catch(error => {
                    document.getElementById('savedFiltersList').innerHTML = 
                        '<div class="alert alert-danger">Error loading filters</div>';
                });
        }

        function displaySavedFilters(filters) {
            const container = document.getElementById('savedFiltersList');
            
            if (filters.length === 0) {
                container.innerHTML = '<div class="text-center text-muted">No saved filters found</div>';
                return;
            }

            let html = '<div class="row g-3">';
            filters.forEach(filter => {
                html += `
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="card-title">${filter.filter_name}</h6>
                                        <small class="text-muted">Created: ${new Date(filter.created_at).toLocaleDateString()}</small>
                                        ${filter.is_global ? '<span class="badge bg-info ms-2">Global</span>' : ''}
                                        ${filter.is_default ? '<span class="badge bg-primary ms-2">Default</span>' : ''}
                                    </div>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                            Actions
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="#" onclick="loadSavedFilter(${filter.id})">Load</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="setAsDefault(${filter.id})">Set as Default</a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><a class="dropdown-item text-danger" href="#" onclick="deleteSavedFilter(${filter.id})">Delete</a></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            container.innerHTML = html;
        }

        function loadSearchHistory() {
            fetch(`ajax/get_search_history.php?page_type=${pageType}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displaySearchHistory(data.history);
                    } else {
                        document.getElementById('searchHistoryList').innerHTML = 
                            '<div class="alert alert-danger">Error loading history: ' + data.error + '</div>';
                    }
                })
                .catch(error => {
                    document.getElementById('searchHistoryList').innerHTML = 
                        '<div class="alert alert-danger">Error loading history</div>';
                });
        }

        function displaySearchHistory(history) {
            const container = document.getElementById('searchHistoryList');
            
            if (history.length === 0) {
                container.innerHTML = '<div class="text-center text-muted">No search history found</div>';
                return;
            }

            let html = '<div class="list-group">';
            history.forEach(item => {
                const date = new Date(item.search_timestamp).toLocaleString();
                html += `
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1">${item.search_query || 'Advanced Search'}</h6>
                                <p class="mb-1 text-muted">${item.results_count} results</p>
                                <small class="text-muted">${date}</small>
                            </div>
                            <button class="btn btn-sm btn-outline-primary" onclick="repeatSearch('${item.search_query || ''}', ${JSON.stringify(item.filter_criteria || {}).replace(/"/g, '&quot;')})">
                                Repeat
                            </button>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            container.innerHTML = html;
        }

        function saveCurrentFilter() {
            const filterName = document.getElementById('filterName').value;
            const isGlobal = document.getElementById('isGlobal').checked;
            const isDefault = document.getElementById('isDefault').checked;
            const filters = getCurrentFilters();
            
            if (!filterName) {
                alert('Please enter a filter name');
                return;
            }
            
            const formData = new FormData();
            formData.append('filter_name', filterName);
            formData.append('page_type', pageType);
            formData.append('filter_criteria', JSON.stringify(filters));
            formData.append('is_global', isGlobal);
            formData.append('is_default', isDefault);
            
            fetch('ajax/save_search_filter.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('saveFilterModal')).hide();
                    document.getElementById('saveFilterForm').reset();
                    alert('Filter saved successfully!');
                } else {
                    alert('Error saving filter: ' + data.error);
                }
            })
            .catch(error => {
                alert('Error saving filter');
            });
        }

        function recordSearch(query, filters) {
            fetch('ajax/record_search.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    page_type: pageType,
                    search_query: query,
                    filter_criteria: filters
                })
            })
            .catch(error => console.error('Error recording search:', error));
        }

        // Global functions for use in modals
        window.loadSavedFilter = function(filterId) {
            fetch(`ajax/load_saved_filter.php?filter_id=${filterId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        applyFilterCriteria(data.filter.filter_criteria);
                        bootstrap.Modal.getInstance(document.getElementById('savedFiltersModal')).hide();
                        performSearch();
                    } else {
                        alert('Error loading filter: ' + data.error);
                    }
                })
                .catch(error => {
                    alert('Error loading filter');
                });
        };

        window.setAsDefault = function(filterId) {
            fetch('ajax/set_default_filter.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    filter_id: filterId,
                    page_type: pageType
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadSavedFilters();
                } else {
                    alert('Error setting default: ' + data.error);
                }
            })
            .catch(error => {
                alert('Error setting default');
            });
        };

        window.deleteSavedFilter = function(filterId) {
            if (!confirm('Are you sure you want to delete this filter?')) {
                return;
            }
            
            fetch('ajax/delete_saved_filter.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    filter_id: filterId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadSavedFilters();
                } else {
                    alert('Error deleting filter: ' + data.error);
                }
            })
            .catch(error => {
                alert('Error deleting filter');
            });
        };

        window.repeatSearch = function(query, filters) {
            searchInput.value = query;
            applyFilterCriteria(filters);
            bootstrap.Modal.getInstance(document.getElementById('searchHistoryModal')).hide();
            performSearch();
        };

        function applyFilterCriteria(criteria) {
            clearAllFilters();
            
            Object.keys(criteria).forEach(key => {
                const element = document.querySelector(`[name="${key}"]`) || document.querySelector(`[name="${key}[]"]`);
                if (element) {
                    if (element.type === 'checkbox') {
                        element.checked = criteria[key] === true;
                    } else if (element.type === 'radio') {
                        if (element.value === criteria[key]) {
                            element.checked = true;
                        }
                    } else if (element.tagName === 'SELECT' && element.multiple) {
                        if (Array.isArray(criteria[key])) {
                            criteria[key].forEach(value => {
                                const option = element.querySelector(`option[value="${value}"]`);
                                if (option) option.selected = true;
                            });
                        }
                    } else {
                        element.value = criteria[key];
                    }
                }
            });
        }
    });
    </script>
    <?php
}
?>

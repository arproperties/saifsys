<?php
/**
 * Real Estate Document Manager Component
 * Reusable component for managing documents on lease/tenant/unit pages
 */

if (!function_exists('re_fetch_active_document_types')) {
    function re_fetch_active_document_types(PDO $conn, int $companyId): array {
        $stmt = $conn->prepare("
            SELECT id, document_type_name, document_type_code, has_expiry, default_expiry_days
            FROM re_document_types
            WHERE company_id = ? AND is_active = 1
            ORDER BY display_order, document_type_name
        ");
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('re_legacy_document_type_from_code')) {
    function re_legacy_document_type_from_code(string $code): string {
        $map = [
            'EJARI' => 'ejari',
            'TENANCY_CONTRACT' => 'lease_agreement',
            'LEASE_AGREEMENT' => 'lease_agreement',
            'TENANT_PASSPORT' => 'tenant_passport',
            'TENANT_VISA' => 'tenant_visa',
            'EMIRATES_ID' => 'tenant_id',
            'TENANT_ID' => 'tenant_id',
            'NOC' => 'noc',
            'MUNICIPALITY_APPROVAL' => 'municipality_approval',
            'INSURANCE' => 'insurance',
            'BUILDING_PERMIT' => 'building_permit',
            'UTILITY_BILL' => 'utility_connection',
            'UTILITY_CONNECTION' => 'utility_connection',
            'lease_agreement' => 'lease_agreement',
            'ejari' => 'ejari',
            'tenant_id' => 'tenant_id',
            'tenant_passport' => 'tenant_passport',
            'tenant_visa' => 'tenant_visa',
            'noc' => 'noc',
            'municipality_approval' => 'municipality_approval',
            'insurance' => 'insurance',
            'building_permit' => 'building_permit',
            'utility_connection' => 'utility_connection',
            'other' => 'other',
            'OTHER' => 'other',
        ];
        $upper = strtoupper(trim($code));
        if (isset($map[$upper])) {
            return $map[$upper];
        }
        $lower = strtolower(trim($code));
        return $map[$lower] ?? 'other';
    }
}

if (!function_exists('render_document_manager')) {
    function render_document_manager($relatedType, $relatedId, $currentCompanyId) {
        global $conn;
        $availableTypes = re_fetch_active_document_types($conn, (int)$currentCompanyId);
        ?>
        <div class="card mt-4" id="documentsSection">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-file-earmark"></i> Documents</h5>
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                    <i class="bi bi-upload"></i> Upload Document
                </button>
            </div>
            <div class="card-body">
                <?php if ($relatedType === 'lease'): ?>
                    <div class="alert alert-info small py-2 mb-3">
                        <strong>Tenant portal:</strong> When a tenant uploads a <em>signed contract scan</em>, it appears in this list as
                        <strong>Tenant signed tenancy scan</strong> (uploaded by &ldquo;Tenant (portal)&rdquo;). After landlord signature, upload the
                        <strong>final fully executed</strong> PDF using the appropriate document type from your configured list (e.g. Tenancy Contract).
                    </div>
                <?php endif; ?>
                <div id="documentsList">
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-arrow-repeat spin"></i> Loading documents...
                    </div>
                </div>
            </div>
        </div>

        <!-- Upload Document Modal -->
        <div class="modal fade" id="uploadDocumentModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Upload Document</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form id="uploadDocumentForm" enctype="multipart/form-data">
                        <div class="modal-body">
                            <input type="hidden" name="related_type" value="<?= htmlspecialchars($relatedType) ?>">
                            <input type="hidden" name="related_id" value="<?= (int)$relatedId ?>">
                            
                            <div class="mb-3">
                                <label class="form-label">Document Type *</label>
                                <select name="document_type_id" id="documentTypeSelect" class="form-select" required>
                                    <option value="">-- Select Type --</option>
                                    <?php foreach ($availableTypes as $type): ?>
                                        <option value="<?= (int)$type['id'] ?>"
                                                data-has-expiry="<?= (int)($type['has_expiry'] ?? 0) ?>"
                                                data-default-expiry-days="<?= (int)($type['default_expiry_days'] ?? 0) ?>">
                                            <?= htmlspecialchars($type['document_type_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($availableTypes)): ?>
                                    <small class="form-text text-danger">
                                        No document types found.
                                        <a href="compliance_document_types.php" target="_blank">Create document types</a> first.
                                    </small>
                                <?php else: ?>
                                    <small class="form-text text-muted">
                                        Types are managed in
                                        <a href="compliance_document_types.php" target="_blank">Document Types Management</a>.
                                    </small>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">File *</label>
                                <input type="file" name="file" class="form-control" required 
                                       accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx,.txt">
                                <small class="text-muted">Max size: 10MB. Allowed: PDF, Images, Office docs</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Expiry Date (if applicable)</label>
                                <input type="date" name="expires_at" class="form-control">
                                <small class="text-muted">For visas, insurance, etc.</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-control" rows="2"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-upload"></i> Upload
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        (function() {
            const relatedType = '<?= htmlspecialchars($relatedType, ENT_QUOTES) ?>';
            const relatedId = <?= (int)$relatedId ?>;
            
            // Load documents
            function loadDocuments() {
                fetch(`ajax_get_documents.php?related_type=${relatedType}&related_id=${relatedId}`)
                    .then(r => r.json())
                    .then(data => {
                        const container = document.getElementById('documentsList');
                        if (!data.success || data.documents.length === 0) {
                            container.innerHTML = '<div class="text-center text-muted py-3">No documents uploaded yet.</div>';
                            return;
                        }
                        
                        let html = '<div class="table-responsive"><table class="table table-hover">';
                        html += '<thead><tr><th>Type</th><th>File Name</th><th>Size</th><th>Uploaded</th><th>Expires</th><th>Actions</th></tr></thead><tbody>';
                        
                        data.documents.forEach(doc => {
                            const expiryBadge = doc.expires_at ? 
                                (doc.is_expired ? 
                                    '<span class="badge bg-danger">Expired</span>' : 
                                    (doc.is_expiring_soon ? 
                                        `<span class="badge bg-warning">Expires in ${doc.days_until_expiry} days</span>` : 
                                        '<span class="badge bg-success">Valid</span>')) : 
                                '';
                            const typeLabel = doc.document_type_name
                                ? escapeHtml(doc.document_type_name)
                                : (doc.document_type || '').replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                            const isTenantScan = !!doc.is_tenant_portal_scan;
                            const delBtn = isTenantScan ? '' : `<button onclick="deleteDocument(${doc.id})" class="btn btn-sm btn-outline-danger" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>`;
                            
                            html += `<tr>
                                <td>${escapeHtml(typeLabel)}${isTenantScan ? ' <span class="badge bg-secondary">Portal</span>' : ''}</td>
                                <td>${escapeHtml(doc.file_name)}</td>
                                <td>${doc.file_size_formatted}</td>
                                <td><small>${new Date(doc.created_at).toLocaleDateString()}<br>by ${escapeHtml(doc.uploaded_by_name || 'Unknown')}</small></td>
                                <td>${doc.expires_at ? new Date(doc.expires_at).toLocaleDateString() + '<br>' + expiryBadge : '-'}</td>
                                <td>
                                    <a href="../../${escapeHtml(doc.file_path)}" target="_blank" class="btn btn-sm btn-outline-primary" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    ${delBtn}
                                </td>
                            </tr>`;
                        });
                        
                        html += '</tbody></table></div>';
                        container.innerHTML = html;
                    })
                    .catch(err => {
                        document.getElementById('documentsList').innerHTML = 
                            '<div class="alert alert-danger">Error loading documents: ' + err.message + '</div>';
                    });
            }
            
            // Upload document
            document.getElementById('uploadDocumentForm')?.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Uploading...';
                
                fetch('ajax_document_upload.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        bootstrap.Modal.getInstance(document.getElementById('uploadDocumentModal')).hide();
                        this.reset();
                        loadDocuments();
                        showAlert('success', 'Document uploaded successfully');
                    } else {
                        showAlert('danger', data.error || 'Upload failed');
                    }
                })
                .catch(err => {
                    showAlert('danger', 'Error: ' + err.message);
                })
                .finally(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                });
            });
            
            // Delete document
            window.deleteDocument = function(docId) {
                if (!confirm('Are you sure you want to delete this document?')) return;
                
                const formData = new FormData();
                formData.append('document_id', docId);
                
                fetch('ajax_document_delete.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        loadDocuments();
                        showAlert('success', 'Document deleted successfully');
                    } else {
                        showAlert('danger', data.error || 'Delete failed');
                    }
                })
                .catch(err => {
                    showAlert('danger', 'Error: ' + err.message);
                });
            };
            
            // Helper functions
            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
            
            function showAlert(type, message) {
                const alert = document.createElement('div');
                alert.className = `alert alert-${type} alert-dismissible fade show`;
                alert.innerHTML = `${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
                document.getElementById('documentsSection').insertAdjacentElement('beforebegin', alert);
                setTimeout(() => alert.remove(), 5000);
            }
            
            document.getElementById('documentTypeSelect')?.addEventListener('change', function() {
                const opt = this.options[this.selectedIndex];
                const expiryInput = document.querySelector('#uploadDocumentForm input[name="expires_at"]');
                if (!expiryInput || !opt) return;
                const hasExpiry = opt.getAttribute('data-has-expiry') === '1';
                const defaultDays = parseInt(opt.getAttribute('data-default-expiry-days') || '0', 10);
                if (hasExpiry && defaultDays > 0 && !expiryInput.value) {
                    const d = new Date();
                    d.setDate(d.getDate() + defaultDays);
                    expiryInput.value = d.toISOString().slice(0, 10);
                }
            });

            // Load on page load
            loadDocuments();
            
            // Reload when modal is closed (in case of new uploads)
            document.getElementById('uploadDocumentModal')?.addEventListener('hidden.bs.modal', function() {
                loadDocuments();
            });
        })();
        </script>
        
        <style>
        .spin {
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        </style>
        <?php
    }
}


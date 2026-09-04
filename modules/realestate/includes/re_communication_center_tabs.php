<?php
/**
 * Shared tab bar for Tenant Communication Center surfaces.
 * Expects $ccTab = announcements|push|categories
 */
$ccTab = $ccTab ?? 'announcements';
$ccBase = $navBasePath ?? '';
?>
<ul class="nav nav-pills gap-2 mb-4 flex-wrap">
    <li class="nav-item">
        <a class="nav-link <?= $ccTab === 'announcements' ? 'active' : '' ?>"
           href="<?= h($ccBase) ?>tenant_communication_center.php?tab=announcements">
            <i class="bi bi-megaphone"></i> Announcements
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $ccTab === 'push' ? 'active' : '' ?>"
           href="<?= h($ccBase) ?>customer_push_notifications.php">
            <i class="bi bi-send"></i> Quick Push
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $ccTab === 'categories' ? 'active' : '' ?>"
           href="<?= h($ccBase) ?>tenant_communication_center.php?tab=categories">
            <i class="bi bi-tags"></i> Categories
        </a>
    </li>
</ul>

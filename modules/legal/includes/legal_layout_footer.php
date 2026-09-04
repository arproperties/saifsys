        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('legalSbToggle')?.addEventListener('click', function () {
    document.getElementById('legalSb')?.classList.toggle('collapsed');
});
</script>
<?php if (isset($pageScripts)) echo $pageScripts; ?>
</body>
</html>

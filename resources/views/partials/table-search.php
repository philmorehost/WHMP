<?php
/** @var string $target CSS selector for the table this input filters, e.g. "#orders-table" */
/** @var string|null $placeholder */
$placeholder = $placeholder ?? 'Search...';
?>
<input type="search" class="cv-input" data-table-filter="<?= e($target) ?>" placeholder="<?= e($placeholder) ?>" aria-label="<?= e($placeholder) ?>" style="max-width:20rem;">

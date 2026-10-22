<?php
$cover = Shops::imageUrl($shop['cover_path'] ?? null); $logo = Shops::imageUrl($shop['logo_path'] ?? null);
$wa = !empty($shop['phone']) ? preg_replace('/\D/', '', (string)$shop['phone']) : '';
$initial = mb_strtoupper(mb_substr(trim((string)$shop['name']), 0, 1));
?>
<section class="shop-hero">
  <div class="shop-cover<?= $cover ? '' : ' no-img' ?>"<?= $cover ? ' style="background-image:url(\'' . e($cover) . '\')"' : '' ?>></div>
  <div class="shop-id">
    <div class="shop-logo"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt="Logo de <?= e($shop['name']) ?>"><?php else: ?><span><?= e($initial) ?></span><?php endif; ?></div>
    <div class="shop-txt">
      <h1><?= e($shop['name']) ?></h1>
      <?php if ($shop['description']): ?><p class="shop-desc"><?= e($shop['description']) ?></p><?php endif; ?>
      <p class="shop-meta">
        <?php if ($shop['address']): ?><span><i class="bi bi-geo-alt"></i> <?= e($shop['address']) ?></span><?php endif; ?>
        <?php if ($shop['phone']): ?><a href="tel:+<?= e($wa) ?>"><i class="bi bi-telephone"></i> <?= e(Scheduling::formatPhone($shop['phone'])) ?></a><?php endif; ?>
      </p>
    </div>
  </div>
</section>

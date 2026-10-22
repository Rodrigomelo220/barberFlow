<?php foreach ((flash() ?? []) as [$t, $m]): ?>
  <div class="alert <?= $t === 'ok' ? 'alert-success' : 'alert-danger' ?>" role="<?= $t === 'ok' ? 'status' : 'alert' ?>"><?= e($m) ?></div>
<?php endforeach; unset($_SESSION['old']); ?>

<?php
declare(strict_types=1);

/** Auxiliares de visão (HTML). Tudo que vem do usuário passa por e(). */

const WEEKDAYS = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
const APPT_STATUS = ['confirmed' => 'Confirmado', 'completed' => 'Concluído', 'no_show' => 'Faltou', 'canceled' => 'Cancelado'];
const SUB_STATUS = ['incomplete' => 'Aguardando pagamento', 'trialing' => 'Em teste', 'active' => 'Ativa', 'past_due' => 'Em atraso', 'suspended' => 'Suspensa', 'canceled' => 'Cancelada'];

function badge(string $text, string $tone = 'neutral'): string
{
    $c = ['green' => 'success', 'red' => 'danger', 'blue' => 'primary', 'brass' => 'warning', 'neutral' => 'secondary'][$tone] ?? 'secondary';
    return '<span class="badge rounded-pill text-bg-' . $c . '">' . e($text) . '</span>';
}
/** Marca própria (blocos de agenda): SVG em linha, sem depender do nome do sistema. */
function brand_mark(): string
{
    static $n = 0; $id = 'bm' . (++$n);
    return '<svg class="brand-mark" viewBox="0 0 40 40" width="32" height="32" aria-hidden="true" focusable="false"><defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#e0c08a"/><stop offset="1" stop-color="#a4773e"/></linearGradient></defs><rect width="40" height="40" rx="11" fill="url(#' . $id . ')"/><rect x="9" y="10" width="15" height="5" rx="2.5" fill="#242720"/><rect x="16" y="18" width="15" height="5" rx="2.5" fill="#242720"/><rect x="9" y="26" width="10" height="5" rx="2.5" fill="#242720"/><circle cx="29" cy="12.5" r="2.5" fill="#242720"/></svg>';
}
function brand_favicon(): string
{
    return 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40"><rect width="40" height="40" rx="11" fill="#202522"/><rect x="9" y="10" width="15" height="5" rx="2.5" fill="#d7b77e"/><rect x="16" y="18" width="15" height="5" rx="2.5" fill="#d7b77e"/><rect x="9" y="26" width="10" height="5" rx="2.5" fill="#d7b77e"/><circle cx="29" cy="12.5" r="2.5" fill="#d7b77e"/></svg>');
}
function icon(string $name, string $extra = ''): string { return '<i class="bi bi-' . e($name) . ($extra ? ' ' . e($extra) : '') . '" aria-hidden="true"></i>'; }
function appt_badge(string $s): string
{
    $tone = ['confirmed' => 'blue', 'completed' => 'green', 'no_show' => 'red', 'canceled' => 'neutral'][$s] ?? 'neutral';
    return badge(APPT_STATUS[$s] ?? $s, $tone);
}
function fld(string $name, string $label, $value = '', array $o = []): string
{
    $type = $o['type'] ?? 'text';
    $id = 'f_' . preg_replace('/\W+/', '_', $name) . ($o['id'] ?? '');
    $attrs = '';
    foreach (['required', 'readonly', 'autofocus'] as $b) if (!empty($o[$b])) $attrs .= " $b";
    foreach (['placeholder', 'min', 'max', 'step', 'maxlength', 'minlength', 'inputmode', 'autocomplete', 'pattern'] as $a) if (isset($o[$a])) $attrs .= " $a=\"" . e($o[$a]) . '"';
    $hint = isset($o['hint']) ? '<p class="form-text" id="' . $id . '_h">' . e($o['hint']) . '</p>' : '';
    if ($type === 'textarea') return '<div class="field"><label class="form-label" for="' . $id . '">' . e($label) . '</label><textarea class="form-control" id="' . $id . '" name="' . e($name) . '" rows="' . (int)($o['rows'] ?? 3) . '"' . $attrs . '>' . e($value) . '</textarea>' . $hint . '</div>';
    if ($type === 'select') {
        $opts = '';
        foreach ($o['options'] as $k => $t) $opts .= '<option value="' . e($k) . '"' . ((string)$k === (string)$value ? ' selected' : '') . '>' . e($t) . '</option>';
        return '<div class="field"><label class="form-label" for="' . $id . '">' . e($label) . '</label><select class="form-select" id="' . $id . '" name="' . e($name) . '"' . $attrs . '>' . $opts . '</select>' . $hint . '</div>';
    }
    if ($type === 'checkbox') return '<div class="field form-check"><input class="form-check-input" type="checkbox" id="' . $id . '" name="' . e($name) . '" value="1"' . ($value ? ' checked' : '') . '><label class="form-check-label" for="' . $id . '">' . e($label) . '</label>' . $hint . '</div>';
    return '<div class="field"><label class="form-label" for="' . $id . '">' . e($label) . '</label><input class="form-control" id="' . $id . '" type="' . e($type) . '" name="' . e($name) . '" value="' . e($value) . '"' . $attrs . '>' . $hint . '</div>';
}
function post_form(string $action, string $inner, string $class = '', string $confirm = ''): string
{
    return '<form method="post" action="' . e(url($action)) . '"' . ($class ? ' class="' . e($class) . '"' : '') . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field() . $inner . '</form>';
}
function local_date(string $utc, string $tz, string $fmt = 'd/m/Y'): string { return dt_local($utc, $tz, $fmt); }
function weekday_pt(DateTimeInterface $d): string
{
    return ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'][(int)$d->format('w')];
}
function decode_list(?string $json): array { $a = json_decode((string)$json, true); return is_array($a) ? array_map('strval', $a) : []; }

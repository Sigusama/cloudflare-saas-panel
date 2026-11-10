<?php /* SPDX-License-Identifier: AGPL-3.0-only */ ?>
<label class="locale-control"><span><?= e(tr('界面语言')) ?></span><select data-locale-switch aria-label="<?= e(tr('界面语言')) ?>"><?php foreach(\Panel\Core\I18n::languages() as $code=>$label): ?><option value="<?= e($code) ?>" <?= \Panel\Core\I18n::current()===$code?'selected':'' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
<div id="locale-data" hidden><?= e(json_encode(\Panel\Core\I18n::dictionary(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)) ?></div>

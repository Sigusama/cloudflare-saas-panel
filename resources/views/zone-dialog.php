<?php /* SPDX-License-Identifier: AGPL-3.0-only */ ?>
<dialog id="zone-create-dialog"><div class="dialog-head"><h2><?= e(tr('添加 Cloudflare 主域名')) ?></h2><button type="button" class="text-button" data-close="zone-create-dialog" aria-label="<?= e(tr('关闭')) ?>">×</button></div>
<form id="zone-create-form"><fieldset id="zone-create-fields">
<label><?= e(tr('已保存的 Cloudflare 凭据')) ?><select id="zone-credential" name="account_id" required></select></label>
<label><?= e(tr('Cloudflare 目标账号')) ?><select id="zone-cloud-account" required></select></label>
<label id="zone-account-id-label"><?= e(tr('Cloudflare Account ID')) ?><input id="zone-account-id" name="cf_account_id" pattern="[a-fA-F0-9]{32}" maxlength="32" required autocomplete="off" spellcheck="false"><small><?= e(tr('在 Cloudflare 账号概览复制 Account ID；它与面板中的账号编号不同。')) ?></small></label>
<label><?= e(tr('已注册的主域名')) ?><input name="name" placeholder="example.com" required maxlength="253" autocomplete="off" spellcheck="false"></label>
<p class="hint"><?= e(tr('添加完整 DNS 托管 Zone。创建后核对 Cloudflare DNS，再到注册商修改 NS；面板不会购买域名或自动修改注册商设置。')) ?></p>
<p class="hint"><?= e(tr('凭据需有创建目标 Zone 的权限；仅授权已有指定 Zone 的 Token 可能无法添加新域名。')) ?></p>
<p id="zone-create-status" class="hint" role="status"></p>
<div class="dialog-actions"><button type="button" class="secondary" data-close="zone-create-dialog"><?= e(tr('取消')) ?></button><button type="submit" class="primary" id="zone-create-submit"><?= e(tr('在 Cloudflare 创建')) ?></button></div>
</fieldset></form></dialog>
<dialog id="zone-info-dialog"><div class="dialog-head"><h2 id="zone-info-name"></h2><button type="button" class="text-button" data-close="zone-info-dialog" aria-label="<?= e(tr('关闭')) ?>">×</button></div>
<p id="zone-info-status" role="status"></p><h3><?= e(tr('Cloudflare 分配的 NS')) ?></h3><pre id="zone-name-servers"></pre>
<p class="hint"><?= e(tr('先核对并补齐 Cloudflare 中的网站、邮箱等 DNS，再在注册商处设置这些 NS。等待生效后刷新状态；已有主域名会原样复用。')) ?></p>
<div class="dialog-actions"><button type="button" class="secondary" id="zone-info-refresh"><?= e(tr('刷新状态')) ?></button><button type="button" class="primary" id="zone-info-open"><?= e(tr('查看域名记录')) ?></button></div></dialog>

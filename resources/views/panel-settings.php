<!-- SPDX-License-Identifier: AGPL-3.0-only -->
<form class="card" id="panel-settings-form">
  <h2><?= e(tr('面板访问地址')) ?></h2>
  <label><?= e(tr('访问地址')) ?><input name="app_url" id="panel-app-url" type="url" required maxlength="2048" placeholder="https://panel.example.com" autocomplete="url" disabled></label>
  <p class="hint"><?= e(tr('填写本面板的完整访问地址，支持 HTTP、HTTPS 和自定义端口。请先配置新域名解析、Web 服务与 HTTPS，再保存。')) ?></p>
  <p class="hint"><?= e(tr('保存后保留当前页面。换域名后请从新地址重新登录。')) ?></p>
  <div class="actions"><button class="primary" type="submit" disabled><?= e(tr('保存面板地址')) ?></button><button class="secondary" type="button" id="panel-settings-refresh"><?= e(tr('重新读取')) ?></button><a class="secondary" id="panel-url-open" target="_blank" rel="noopener noreferrer" hidden><?= e(tr('打开填写的地址')) ?></a></div>
  <p id="panel-settings-status" class="hint" role="status" aria-live="polite"></p>
</form>

{*
 * uniple checkout — admin config Smarty template for EC-CUBE 2.x
 *
 * 4 系 plugin の Resource/template/admin/config.twig と同等内容を Smarty で再構成。
 * 法令準拠 (= JPYC は電子決済手段) + 返金未対応 + presskit 必須 3 行免責 を必須表示。
 *}

<form name="form1" id="form1" method="post" action="<!--{$smarty.server.REQUEST_URI|escape}-->" autocomplete="off">
<input type="hidden" name="form_action" id="form_action" value="save" />
<input type="hidden" name="transactionid" value="<!--{$arrForm.transactionid|escape}-->" />

<div class="contents-main">

    <h2>uniple checkout 設定</h2>

    <!--{if empty($arrForm.api_key_masked)}-->
    <div class="message" style="border:1px solid #ffd0a8; background:#fff7ec; padding:12px; margin:12px 0;">
        uniple checkout のご利用には加盟店申請が必要です。
        <a href="https://forms.gle/b8kwVZeynA1ffV8j6" target="_blank" rel="noopener noreferrer">こちらから申請してください</a>
        (= 承認後 API key / webhook secret が発行されます)。
    </div>
    <!--{/if}-->

    <!-- ⚖️ JPYC の法令上の分類 -->
    <div class="message" style="border:1px solid #d0e3ff; background:#eef5ff; padding:12px; margin:12px 0;">
        <strong>⚖️ JPYC の法令上の分類</strong><br>
        JPYC は <strong>電子決済手段</strong>（資金決済法第 2 条第 5 項に基づく）です。
        JPYC 株式会社が発行する資金移動業型ステーブルコイン（関東財務局長第 00099 号、1 JPYC = 1 円で発行・償還）。
        <strong>暗号資産ではありません</strong>。<br>
        本 plugin は uniple が PSP として介在する設計のため、加盟店側の<strong>電子決済手段等取引業（資金決済法第 2 条第 10 項）登録は不要</strong>です。
    </div>

    <!-- ⚠️ 返金について -->
    <div class="message" style="border:1px solid #ffd0a8; background:#fff7ec; padding:12px; margin:12px 0;">
        <strong>⚠️ 返金について</strong><br>
        uniple JPYC 決済では、加盟店ダッシュボードからの自動返金には対応していません。
        返金が必要な場合は、加盟店から購入者へ JPYC を直接送金してください。
        ※ uniple はノンカストディ型決済のため、加盟店 → 購入者の直接送金以外の返金経路はありません。
    </div>

    <!-- ℹ️ 経路選択 SSOT 案内 -->
    <div class="message" style="border:1px solid #d0e3ff; background:#eef5ff; padding:12px; margin:12px 0;">
        <strong>ℹ️ 経路選択 (= LINE 経由 / WC 直) について</strong><br>
        経路選択は <strong>uniple admin で一元管理</strong>されています (= MerchantSite.checkoutMode)。
        本 plugin 設定画面では経路設定は提供していません。 経路変更を希望される場合は uniple サポート (= support@uniple.io) までご連絡ください。<br>
        詳細: docs/migration-notes.md
    </div>

    <!--{if !empty($arrInfo)}-->
        <!--{foreach from=$arrInfo item=msg}-->
            <p class="message" style="color:green;"><!--{$msg|escape}--></p>
        <!--{/foreach}-->
    <!--{/if}-->

    <table class="form">
        <tr>
            <th>Merchant API Key <span class="attention">*</span></th>
            <td>
                <input type="text" name="api_key" value="" size="60" maxlength="255" autocomplete="off" placeholder="空欄なら保存済みの値を維持" />
                <!--{if !empty($arrErr.api_key)}--><p class="attention"><!--{$arrErr.api_key|escape}--></p><!--{/if}-->
                <p class="info-msg">uniple ダッシュボードで発行された Merchant API Key (Bearer)。<!--{if !empty($arrForm.api_key_masked)}--> 保存済み: <!--{$arrForm.api_key_masked|escape}--><!--{/if}--></p>
            </td>
        </tr>
        <tr>
            <th>Webhook Signing Secret <span class="attention">*</span></th>
            <td>
                <input type="password" name="webhook_secret" value="" size="60" maxlength="255" autocomplete="new-password" placeholder="空欄なら保存済みの値を維持" />
                <!--{if !empty($arrErr.webhook_secret)}--><p class="attention"><!--{$arrErr.webhook_secret|escape}--></p><!--{/if}-->
                <p class="info-msg">webhook (X-Uniple-Signature) の HMAC-SHA256 検証に使う共有 secret。<!--{if !empty($arrForm.webhook_secret_masked)}--> 保存済み: <!--{$arrForm.webhook_secret_masked|escape}--><!--{/if}--></p>
            </td>
        </tr>
        <tr>
            <th>加盟店表示名 <span class="attention">*</span></th>
            <td>
                <input type="text" name="merchant_label" value="<!--{$arrForm.merchant_label|escape}-->" size="40" maxlength="100" />
                <!--{if !empty($arrErr.merchant_label)}--><p class="attention"><!--{$arrErr.merchant_label|escape}--></p><!--{/if}-->
                <p class="info-msg">uniple checkout 画面で買い手に見える名前。</p>
            </td>
        </tr>
        <tr>
            <th>API Base URL</th>
            <td>
                <input type="text" name="api_base_url" value="<!--{$arrForm.api_base_url|escape}-->" size="60" maxlength="255" placeholder="https://uniple.io" />
                <!--{if !empty($arrErr.api_base_url)}--><p class="attention"><!--{$arrErr.api_base_url|escape}--></p><!--{/if}-->
                <p class="info-msg">通常は <code>https://uniple.io</code> のまま。検証用のみ変更。</p>
            </td>
        </tr>
        <tr>
            <th>動作モード <span class="attention">*</span></th>
            <td>
                <label><input type="radio" name="mode" value="live" <!--{if $arrForm.mode eq 'live'}-->checked="checked"<!--{/if}--> /> Live (本番)</label>
                <label style="margin-left:16px;"><input type="radio" name="mode" value="test" <!--{if $arrForm.mode eq 'test'}-->checked="checked"<!--{/if}--> /> Test (低額実決済で動作確認)</label>
                <p class="info-msg">uniple 本体に test mode endpoint は存在しないため、Test モードは「最小金額の実決済を流して動作確認するモード」を意味します。実際に支払が走る点に注意してください。</p>
            </td>
        </tr>
    </table>

    <h3>uniple admin に登録する URL</h3>
    <p>uniple ダッシュボードの加盟店設定 (<code>/admin/merchants/</code>) で、以下の URL を登録してください:</p>
    <table class="form">
        <tr><th>Webhook 受信 URL</th><td><code><!--{$webhookUrl|escape}--></code></td></tr>
        <tr><th>Allowed Success URL</th><td><code><!--{$returnUrl|escape}--></code></td></tr>
        <tr><th>Allowed Cancel URL</th><td><code><!--{$cancelUrl|escape}--></code></td></tr>
    </table>
    <p class="info-msg">配送 retry は 7 attempts / 約 3 日間 (1m → 5m → 30m → 2h → 6h → 24h → 48h)。署名は HMAC-SHA256 (header: X-Uniple-Signature: sha256=&lt;hex&gt;)。</p>

    <h3>x402 / AI購入 商品同期</h3>
    <div class="message" style="border:1px solid #d0e3ff; background:#eef5ff; padding:12px; margin:12px 0;">
        EC-CUBE2の商品マスタをunipleの商品catalogへ同期します。公開中・販売可能・価格ありの商品規格は「有効」として同期されます。<br>
        通常のHosted Checkout / LINE / WalletConnect決済フローは変更されません。
        <!--{if !empty($arrErr.x402_sync)}--><p class="attention"><!--{$arrErr.x402_sync|escape}--></p><!--{/if}-->
        <!--{if !empty($x402SyncMessage)}--><p class="message" style="color:green; margin:8px 0 0;"><!--{$x402SyncMessage|escape}--></p><!--{/if}-->
        <p style="margin:12px 0 0;">
            <button type="submit" class="btn" onclick="document.getElementById('form_action').value='x402_sync';">x402商品同期</button>
        </p>
        <!--{if !empty($x402Products)}-->
        <p style="margin:12px 0 8px;">
            <button type="button" class="btn" onclick="unipleX402SetAiTarget('all');">全て選択</button>
            <button type="button" class="btn" onclick="unipleX402SetAiTarget('none');">全て解除</button>
            <button type="button" class="btn" onclick="unipleX402SetAiTarget('ec_active');">EC側で有効な商品だけ選択</button>
        </p>
        <table class="list" style="margin-top:12px; width:100%;">
            <tr>
                <th style="width:70px;">AI購入対象</th>
                <th>商品規格</th>
                <th style="width:90px;">価格</th>
                <th style="width:80px;">EC状態</th>
            </tr>
            <!--{foreach from=$x402Products item=product}-->
            <tr>
                <td style="text-align:center;">
                    <input type="checkbox" class="uniple-x402-ai-target" data-ec-active="<!--{if $product.ecActive}-->1<!--{else}-->0<!--{/if}-->" name="x402_ai_enabled[]" value="<!--{$product.externalId|escape}-->" <!--{if $product.aiEnabled}-->checked="checked"<!--{/if}--> />
                </td>
                <td>
                    <!--{$product.name|escape}--><br>
                    <span class="info-msg"><!--{$product.externalId|escape}--></span>
                </td>
                <td><!--{if $product.priceJpyc ne ''}--><!--{$product.priceJpyc|escape}--> JPYC<!--{else}-->同期対象外<!--{/if}--></td>
                <td><!--{if $product.ecActive}-->有効<!--{else}-->無効<!--{/if}--></td>
            </tr>
            <!--{/foreach}-->
        </table>
        <p style="margin:12px 0 0;">
            <button type="submit" class="btn" onclick="document.getElementById('form_action').value='x402_settings_save';">AI購入対象設定を保存</button>
        </p>
        <!--{/if}-->
    </div>

    <!-- presskit 必須 3 行免責表記 -->
    <div class="message" style="border:1px solid #ddd; background:#fafafa; padding:12px; margin:24px 0; font-size:0.9em; color:#666;">
        <ul style="margin:0; padding-left:20px;">
            <li>本サービス／プラグインは JPYC 株式会社による公式コンテンツではありません。</li>
            <li>「JPYC」は JPYC 株式会社の提供するステーブルコインです。</li>
            <li>JPYC 及び JPYC ロゴは、JPYC 株式会社の登録商標です。</li>
        </ul>
    </div>

    <p style="text-align:center; margin-top:24px;">
        <button type="submit" class="btn btn-primary">登録</button>
    </p>

    {* uniple 本体側 footer 統一 (= BUILD jHDKVJ-U2CTLw9MhBKrw_、 全 page 整合) *}
    <div style="text-align:center; margin-top:24px; line-height:1.6;">
        <div style="font-size:0.85rem; font-weight:600; color:#374151;">© uniple inc.</div>
        <div style="font-size:0.7rem; color:#6b7280;">JPYC及びJPYCロゴは、JPYC株式会社の登録商標です。</div>
    </div>

</div>

</form>

<script type="text/javascript">
function unipleX402SetAiTarget(mode) {
    var boxes = document.querySelectorAll('.uniple-x402-ai-target');
    for (var i = 0; i < boxes.length; i++) {
        if (mode === 'all') {
            boxes[i].checked = true;
        } else if (mode === 'none') {
            boxes[i].checked = false;
        } else if (mode === 'ec_active') {
            boxes[i].checked = boxes[i].getAttribute('data-ec-active') === '1';
        }
    }
}
</script>

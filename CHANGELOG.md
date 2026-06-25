# Changelog

All notable changes to this project will be documented in this file.

## [0.1.6] - 2026-06-25
### Added
- x402 / AI購入の商品規格単位ON/OFF設定を追加
- 商品同期時に `replace:true, scope:"site"` を送信し、削除済み商品などのstale rowを無効化

## [0.1.5] - 2026-06-25
### Fixed
- 管理画面のx402商品同期結果を同期ボタン付近にも表示し、同期成否を確認しやすく修正

## [0.1.4] - 2026-06-25
### Fixed
- 管理画面のx402商品同期後にEC-CUBE2のクエリ状態が残り、設定再読込でシステムエラーになる問題を修正

## [0.1.3] - 2026-06-23
### Fixed
- CLIからx402商品同期を実行する場合に、公開商品URLへ `UNIPLE_PUBLIC_SITE_URL` を指定できるように修正

## [0.1.2] - 2026-06-23
### Added
- x402 / AI購入向けの商品同期ボタンを管理画面に追加
- x402完了webhookからEC-CUBE2注文を作成し、入金済みにする処理を追加

## [0.1.1] - 2026-05-21
### Added
- 加盟店申請 Form URL 動線 (templates/admin/config.tpl + README)

## [0.1.0] - 2026-05-03
### Added
- 初回 release: EC-CUBE 2 plugin 基本機能

# GitHub Release Notes Template

## uniple checkout for EC-CUBE 2 v0.1.1

### Supported Versions

| Component | Version |
|---|---|
| EC-CUBE | 2.17.x |
| PHP | 7.0+ (PHP 5.6 is not supported) |
| DB | MariaDB 10.x / MySQL 5.7+ |

### Installation

1. Download `uniple-checkout-eccube2-0.1.1.zip` from this release.
2. Extract the `UnipleJpyc/` directory under `data/downloads/plugin/`.
3. In EC-CUBE admin, open Owners Store > Plugin Management, install and enable `UnipleJpyc`.
4. Open the plugin settings screen and enter the uniple API key, webhook secret, merchant label, API base URL, and mode.
5. Copy or deploy `html_plugin/UnipleJpyc/` to `html/plugin/UnipleJpyc/` if your EC-CUBE install flow does not copy plugin public files automatically.

### Checksums

```text
SHA256  uniple-checkout-eccube2-0.1.1.zip  <paste sha256sum here>
```

Generate with:

```bash
sha256sum build/uniple-checkout-eccube2-0.1.1.zip
```

### Support

- Merchant application: https://forms.gle/b8kwVZeynA1ffV8j6
- Bug reports / questions: support@uniple.io

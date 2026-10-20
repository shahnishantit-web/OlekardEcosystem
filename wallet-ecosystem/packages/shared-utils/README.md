# shared-utils

Reserved for cross-platform shared PHP utilities (helpers, type aliases, constants) reusable by multiple domain modules within the OleKard platform.

**Status**: Empty at Platform Foundation phase. Domain epics populate this package as common utilities are identified.

**License**: Proprietary — internal reuse only within the OleKard platform monorepo.

**Usage**: Add as a Composer path dependency in any module that requires shared utilities:

```json
"require": {
    "olekard/shared-utils": "*"
}
```

```json
"repositories": [
    {"type": "path", "url": "../../packages/shared-utils"}
]
```

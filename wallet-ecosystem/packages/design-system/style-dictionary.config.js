const StyleDictionary = require('style-dictionary');

// Custom Dart formatter — emits OkTokens abstract final class
StyleDictionary.registerFormat({
  name: 'dart/ok-tokens',
  formatter: function ({ dictionary }) {
    const tokens = dictionary.allTokens.map((token) => {
      if (token.attributes.category === 'color') {
        const hex = token.value.replace('#', '');
        const fullHex = hex.length === 6 ? `FF${hex}` : hex;
        return `  static const Color ${token.name} = Color(0x${fullHex.toUpperCase()});`;
      }
      if (token.attributes.category === 'size' || token.attributes.category === 'spacing') {
        return `  static const double ${token.name} = ${parseFloat(token.value)};`;
      }
      return `  static const String ${token.name} = '${token.value}';`;
    });

    return [
      "// Design Tokens — OleKard Wallet Ecosystem",
      "// DO NOT EDIT — update source files and rebuild via npm run tokens:build",
      "import 'package:flutter/material.dart';",
      '',
      'abstract final class OkTokens {',
      tokens.join('\n'),
      '}',
    ].join('\n');
  },
});

// Per-app CSS selector formatter — emits [data-theme="<app>"] { … }
StyleDictionary.registerFormat({
  name: 'css/theme-selector',
  formatter: function ({ dictionary, options }) {
    const selector = options.selector || ':root';
    const vars = dictionary.allTokens
      .map((t) => `  --${t.name}: ${t.value};`)
      .join('\n');
    return `${selector} {\n${vars}\n}\n`;
  },
});

// NOTE: build/css/tokens.css and build/dart/tokens.dart are currently maintained
// by hand because they contain multi-theme blocks ([data-theme="admin/consumer/agent"])
// and fully-named palette tokens not yet expressible by this config alone.
// Run `npm run tokens:build` only after migrating to Style Dictionary 4 transforms
// that support theme-selector output per app.

module.exports = {
  source: ['tokens/**/*.json'],
  platforms: {
    // ── Primitive CSS variables (all tokens, :root) ──────────────
    css: {
      transformGroup: 'css',
      prefix: '',
      buildPath: 'build/css/',
      files: [
        {
          destination: 'tokens.css',
          format: 'css/variables',
          options: { selector: ':root' },
        },
      ],
    },

    // ── Per-app theme selector outputs (future use) ───────────────
    'css-admin': {
      transformGroup: 'css',
      prefix: 'ok',
      buildPath: 'build/css/',
      files: [
        {
          destination: 'themes/admin.css',
          format: 'css/theme-selector',
          filter: (token) => token.path[0] === 'appTeal',
          options: { selector: '[data-theme="admin"]' },
        },
      ],
    },
    'css-consumer': {
      transformGroup: 'css',
      prefix: 'ok',
      buildPath: 'build/css/',
      files: [
        {
          destination: 'themes/consumer.css',
          format: 'css/theme-selector',
          filter: (token) => token.path[0] === 'appGreen',
          options: { selector: '[data-theme="consumer"]' },
        },
      ],
    },
    'css-agent': {
      transformGroup: 'css',
      prefix: 'ok',
      buildPath: 'build/css/',
      files: [
        {
          destination: 'themes/agent.css',
          format: 'css/theme-selector',
          filter: (token) => token.path[0] === 'appBlue',
          options: { selector: '[data-theme="agent"]' },
        },
      ],
    },

    // ── Dart tokens (OkTokens) ────────────────────────────────────
    dart: {
      transformGroup: 'js',
      buildPath: 'build/dart/',
      transforms: ['attribute/cti', 'name/cti/camel', 'color/hex8'],
      files: [
        {
          destination: 'tokens.dart',
          format: 'dart/ok-tokens',
        },
      ],
    },
  },
};

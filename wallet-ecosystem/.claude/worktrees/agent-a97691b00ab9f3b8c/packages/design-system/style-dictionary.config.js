const StyleDictionary = require('style-dictionary');

module.exports = {
  source: ['tokens/**/*.json'],
  platforms: {
    css: {
      transformGroup: 'css',
      prefix: '',
      buildPath: 'build/css/',
      files: [
        {
          destination: 'tokens.css',
          format: 'css/variables',
          options: {
            selector: ':root',
          },
        },
      ],
    },
    dart: {
      transformGroup: 'js',
      buildPath: 'build/dart/',
      transforms: ['attribute/cti', 'name/cti/camel', 'color/hex8'],
      files: [
        {
          destination: 'tokens.dart',
          format: 'dart/class',
        },
      ],
    },
  },
};

// Custom Dart format
StyleDictionary.registerFormat({
  name: 'dart/class',
  formatter: function ({ dictionary }) {
    const tokens = dictionary.allTokens.map((token) => {
      const name = token.name.replace(/-/g, '_').toUpperCase();
      const value = token.value;

      if (token.attributes.category === 'color') {
        const hex = value.replace('#', '');
        const fullHex = hex.length === 6 ? `FF${hex}` : hex;
        return `  static const Color ${token.name.replace(/-/g, '_')} = Color(0x${fullHex.toUpperCase()});`;
      }

      return `  static const String ${token.name.replace(/-/g, '_')} = '${value}';`;
    });

    return [
      "import 'package:flutter/material.dart';",
      '',
      'abstract class AppTokens {',
      tokens.join('\n'),
      '}',
    ].join('\n');
  },
});

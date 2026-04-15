const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
  ...defaultConfig,
  entry: (...args) => {
    const defaultEntries =
      typeof defaultConfig.entry === 'function'
        ? defaultConfig.entry(...args)
        : defaultConfig.entry || {};

    return {
      ...defaultEntries,
      'js/index': path.resolve(process.cwd(), 'src/js/index.js'),
      'css/index': path.resolve(process.cwd(), 'src/css/index.scss'),
      'modules/sources/index': path.resolve(process.cwd(), 'src/js/modules/sources/index.js'),
      'modules/draft/index': path.resolve(process.cwd(), 'src/js/modules/draft/index.js'),
      'modules/review/index': path.resolve(process.cwd(), 'src/js/modules/review/index.js'),
    };
  },
  output: {
    ...defaultConfig.output,
    path: path.resolve(process.cwd(), 'bundle'),
  },
};

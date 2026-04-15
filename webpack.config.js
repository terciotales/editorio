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
    };
  },
  output: {
    ...defaultConfig.output,
    path: path.resolve(process.cwd(), 'bundle'),
  },
};


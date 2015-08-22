var webpack = require("webpack");

module.exports = {
  cache: true,
  entry: './src/index.js',
  output: {
    path: __dirname + '/build/',
    filename: 'bundle.js'
  },
  devtool: 'source-map',
  externals: ['jQuery'],
  module: {
    loaders: [
      //{ test: require.resolve('./src/index.js'), loader: "imports?$=>jQuery"},
      {
        test: /\.js$/,
        include: /src/,
        loader: 'babel-loader',
        query: {modules: 'common'}
      },
      {
        test: /\.less$/,
        loader: 'style!css!less'
      },
      {
        test: /\.json$/,
        loader: 'json'
      }
    ]
  }
};

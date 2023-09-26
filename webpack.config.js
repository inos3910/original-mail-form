const path = require('path');
const MiniCssExtractPlugin = require("mini-css-extract-plugin");

const is_production = process.env.NODE_ENV === 'production';
const MODE = is_production ? "production" : "development";

module.exports = {
  entry: './src/index.js',
  output: { 
    filename: 'main.js',
    path: path.resolve(__dirname, 'dist'),
  },
  module: {
    rules: [
    {
      test: /\.(scss|sass|css)$/i,
      use: [ MiniCssExtractPlugin.loader, 'css-loader', 'sass-loader'],
    },
    ],
  },
  plugins: [
    new MiniCssExtractPlugin({
      filename: 'style.css',
    }),
    ],
  devtool: is_production ? 'eval' : 'source-map',
  watchOptions: {
    ignored: /node_modules/
  }
}
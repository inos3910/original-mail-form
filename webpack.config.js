const path                 = require('path');
const mqpacker             = require('css-mqpacker');
const autoprefixer         = require('autoprefixer');
const MiniCssExtractPlugin = require("mini-css-extract-plugin");
const TerserPlugin         = require("terser-webpack-plugin");

const is_production        = process.env.NODE_ENV === 'production';
const MODE                 = is_production ? "production" : "development";

module.exports = {
  entry: './src/index.js',
  output: { 
    filename: './js/[name].js',
    path: path.resolve(__dirname, 'dist'),
    clean: true
  },
  //パッケージのライセンス情報をjsファイルの中に含める
  optimization: {
    minimizer: [
      new TerserPlugin({
        terserOptions: {
          compress: {
            drop_console: is_production,
          },
          format: {
            ascii_only: true,
          },
        },
        extractComments: false,
      }),
      ],
  },
  module: {
    rules: [
    {
      test: /\.js$/,
      use: [
      {
        loader: "babel-loader",
        options: {
          presets: [
            "@babel/preset-env",
            ],
        },
      },
      ],
    },
    {
      test: /\.(scss|sass|css)$/i,
      use: [
      // CSSファイルを抽出
      {
        loader: MiniCssExtractPlugin.loader,
      },
      // CSSをバンドルする
      {
        loader:'css-loader',
        options: {
          url: false,
          sourceMap: !is_production,
          // postcss-loader と sass-loader の場合は2を指定
          importLoaders: 2,
          // 0 => no loaders (default);
          // 1 => postcss-loader;
          // 2 => postcss-loader, sass-loader
        },
      },
      // PostCSS（autoprefixer）の設定
      {
        loader: 'postcss-loader',
        options: {
          sourceMap: !is_production,
          postcssOptions: {
            plugins: [
              autoprefixer({
                cascade: false,
                grid: true
              }),
              mqpacker({
                sort: true
              })
              ],
          },
        },
      },
      // Sass → CSS
      {
        loader: 'sass-loader',
        options: {
          sourceMap: !is_production,
        }
      }
      ]
    }
    ]
  },
  plugins: [
    new MiniCssExtractPlugin({
      filename: './css/style.css',
    }),
    ],
  // devtool: is_production ? 'eval' : 'source-map',
  devtool: 'source-map',
  watchOptions: {
    ignored: /node_modules/
  },
  target: ['web'],
  resolve: {
    // 拡張子を配列で指定
    extensions: ['.js'],
  }
}
module.exports = {
  publicPath: process.env.NODE_ENV === "production" ? "/mobile" : "/",
  chainWebpack: config => {
    config.resolve.symlinks(false);
  },
  devServer: {
//    proxy: "http://10.0.0.100:80/"
    proxy: "http://localhost:808/"
  }
};

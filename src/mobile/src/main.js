import Vue from "vue";
import App from "./App.vue";
import router from "./router";
import axios from "axios";

require("./assets/icons/animal/style.css");
require("./assets/icons/divers/style.css");
require("./assets/icons/fashion/style.css");
require("./assets/icons/loisir/style.css");
require("./assets/icons/maison/style.css");
require("./assets/icons/meteo/style.css");
require("./assets/icons/nature/style.css");
require("./assets/icons/nextdom/style.css");
require("./assets/icons/nextdom2/style.css");
require("./assets/icons/nextdomapp/style.css");
require("./assets/icons/nourriture/style.css");
require("./assets/icons/personne/style.css");
require("./assets/icons/securite/style.css");
require("./assets/icons/transport/style.css");
require("../node_modules/font-awesome/css/font-awesome.css");
require("../node_modules/font-awesome5/css/fontawesome-all.css");

Vue.config.productionTip = false;

/**
 * Route to login if not connected
 */
router.beforeEach((to, from, next) => {
  if (localStorage.getItem("token") !== null) {
    axios.defaults.headers.common["X-AUTH-TOKEN"] = localStorage.getItem(
      "token"
    );
    next();
  } else if (to.name === "login") {
    next();
  } else {
    next("/login");
  }
});

new Vue({
  router,
  render: h => h(App)
}).$mount("#app");

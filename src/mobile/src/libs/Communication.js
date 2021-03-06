/* This file is part of NextDom Software.
 *
 * NextDom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * NextDom Software is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with NextDom Software. If not, see <http://www.gnu.org/licenses/>.
 *
 * @Support <https://www.nextdom.org>
 * @Email   <admin@nextdom.org>
 * @Authors/Contributors: Sylvaner, Byackee, cyrilphoenix71, ColonelMoutarde, edgd1er, slobberbone, Astral0, DanoneKiD
 */
import axios from "axios";

/**
 * @vuese
 * Rest API communication helper
 */
export default {
  name: "Communication",
  lastError: {},
  connected: false,
  tokenDuration: 10,
  /**
   * Initialise Communication helper
   * @param {router} router Vue router for redirection to login
   */
  init(router) {
    this.router = router;
  },
  /**
   * Ajax get query
   * @param {String} url API url
   * @param {function} callbackFunc Function called on response
   * @param {function} errorCallbackFunc Function called on error
   */
  get(url, callbackFunc, errorCallbackFunc) {
    axios
      .get(url)
      .then(response => {
        callbackFunc(response.data);
      })
      .catch(error => {
        if (error.response !== undefined && error.response.status === 403) {
          localStorage.setItem("token", null);
          this.router.push("/login");
        } else {
          if (errorCallbackFunc !== undefined) {
            errorCallbackFunc(error.response);
          } else {
            console.log(error);
          }
        }
      });
  },
  /**
   * Ajax put query
   * @param {String} url API url
   * @param {Function} callbackFunc  Function called on response
   * @param {Function} errorCallbackFunc  Function called on error
   */
  post(url, callbackFunc, errorCallbackFunc) {
    axios
      .post(url)
      .then(response => {
        if (callbackFunc !== undefined) {
          callbackFunc(response);
        }
      })
      .catch(error => {
        if (errorCallbackFunc !== undefined) {
          errorCallbackFunc(error.response.data);
        }
      });
  },
  /**
   * Ajax put query with post options
   * @param {String} url API url
   * @param {Object} postOptions Options to send
   * @param {Function} callbackFunc  Function called on response
   * @param {Function} errorCallbackFunc  Function called on error
   */
  postWithOptions(url, postOptions, callbackFunc, errorCallbackFunc) {
    // Transform options needed for $_POST filled
    let data = new FormData();
    for (let postOptionsKey in postOptions) {
      data.append(postOptionsKey, postOptions[postOptionsKey]);
    }
    axios
      .post(url, data)
      .then(response => {
        if (callbackFunc !== undefined) {
          callbackFunc(response);
        }
      })
      .catch(error => {
        if (errorCallbackFunc !== undefined) {
          errorCallbackFunc(error.response.data);
        }
      });
  },
  /**
   * Connect to API and get JWT token
   * @param {*} username User login
   * @param {*} password User password
   * @param {*} callbackFunc Function called after connection try
   */
  connect(username, password, callbackFunc) {
    this.removeXAuthToken();
    axios
      .get("/api/connect?login=" + username + "&password=" + password)
      .then(response => {
        this.saveXAuthToken(response.data.token);
        this.connected = true;
        callbackFunc(true);
      })
      .catch(error => {
        this.connected = false;
        this.lastError = {
          status: error.response.status,
          error: error.response.data
        };
        callbackFunc(false);
      });
  },
  /**
   * Get connection state
   */
  isConnected() {
    if (!this.connected) {
      this.reconnect();
    }
    return this.connected;
  },
  /**
   * Try to reconnect if token is always valid
   */
  reconnect() {
    const timestampToHours = 1000 * 60 * 60;
    if (localStorage.getItem("token") !== undefined) {
      const tokenCreationDate = localStorage.getItem("tokenCreationDate");
      if (tokenCreationDate !== undefined) {
        const now = new Date();
        const nowTimestamp = now.valueOf();
        const timeDiff = nowTimestamp - tokenCreationDate;
        if (timeDiff / timestampToHours < this.tokenDuration) {
          axios.defaults.headers.common["X-AUTH-TOKEN"] = localStorage.getItem(
            "token"
          );
          this.connected = true;
        }
      }
    }
  },
  /**
   * Disconnect user
   */
  disconnect() {
    this.removeXAuthToken();
    this.connected = false;
  },
  /**
   * Get last query error
   */
  getLastError() {
    return this.lastError;
  },
  /**
   * Save token in local storage
   * @param {*} token JWT token to save
   */
  saveXAuthToken(token) {
    this.connected = false;
    const creationDate = new Date();
    // Store data in localStorage
    localStorage.setItem("token", token);
    localStorage.setItem("tokenCreationDate", creationDate.valueOf());
    axios.defaults.headers.common["X-AUTH-TOKEN"] = token;
  },
  /**
   * Remove X auth token data
   */
  removeXAuthToken() {
    localStorage.removeItem("token");
    localStorage.removeItem("tokenCreationDate");
    if (axios.defaults.headers.common.hasOwnProperty("X-AUTH-TOKEN")) {
      delete axios.defaults.headers.common["X-AUTH-TOKEN"];
    }
  }
};

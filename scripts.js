var pp = console.log;

var clone = (obj) => JSON.parse(JSON.stringify(obj));

var modal = {
  backdropNode: null,
  open() {
    document.body.className = 'modal-open';
    if (!this.backdropNode) {
      this.backdropNode = document.createElement('div');
      this.backdropNode.className = 'modal-backdrop fade show';
    }
    document.body.appendChild(this.backdropNode);
  },
  close() {
    document.body.className = '';
    this.backdropNode && document.body.removeChild(this.backdropNode);
    this.backdropNode = null;
  }
};

var app = new Vue({
  el: '#app',
  data: {
    adHTML: window.globalAdHTML,
    initialized: false,
    requesting: false,
    alert: null,
    mode: null,
    info: null,
    stories: null,
    page: null,
    formInfo: null,
    formStories: null,
    formStory: null,
    formPage: null,
    formLogin: {},
  },
  watch: {
    mode(nextMode) {
      this.updateData(nextMode);
    },
    formStory(nextFormStory) {
      modal[nextFormStory ? 'open' : 'close']();
    }
  },
  methods: {
    requestApi(rawaction, options) {
      this.requesting = true;
      options = Object.assign({
        credentials: 'include',
      }, options || {});
      return fetch('api.php?action=' + rawaction, options)
        .then((res) => res.json())
        .then((data) => {
          this.requesting = false;
          if (data.error) {
            this.alert = {
              type: 'warning',
              message: data.error.message,
            };
            window.scrollTo(0, 0);
            throw data;
          }
          this.alert = null;
          return data;
        });
    },
    getApi(action, params) {
      action = encodeURIComponent(action);
      if (params) {
        for (var k in params) {
          action += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        }
      }
      return this.requestApi(action);
    },
    postApi(action, params) {
      action = encodeURIComponent(action);
      var formData = new FormData();
      for (var k in params) {
        formData.append(k, params[k]);
      }
      return this.requestApi(action, {
        method: 'POST',
        body: formData,
      });
    },
    andFetchApi(data) {
      return this.fetchData().then(() => {
        if (data.message) {
          this.alert = {
            type: 'info',
            message: data.message,
          };
        }
        window.scrollTo(0, 0);
      });
    },
    postAndFetchApi(action, params) {
      return this.postApi(action, params).then((data) => this.andFetchApi(data));
    },
    updateData(mode) {
      this.alert = null;
      if (mode && this[mode]) {
        var formName = 'form' + mode.replace(/^./, (x) => x.toUpperCase());
        this[formName] = clone(this[mode]);
        if (mode === 'info') this[formName].methodid = '2';
      }
    },
    login() {
      this.postApi('login', {
        id: this.formLogin.id,
        password: this.formLogin.password,
      }).then(() => {
        location.reload();
      });
    },
    fetchData() {
      return this.getApi('get.data').then((data) => {
        data.stories.forEach((story, storyIndex) => {
          story.index = storyIndex;
          story.pages.forEach((page, pageIndex) => {
            page.index = pageIndex;
            page.storyIndex = storyIndex;
          });
        });
        this.info = data.info;
        this.stories = data.stories;
        this.updateData(this.mode);
        return data;
      });
    },
    updateInfo() {
      this.postAndFetchApi('update.info', this.formInfo);
    },

    updateStory() {
      if (this.formStory.title) {
        this.postAndFetchApi('update.story', {
          story: this.formStory.story,
          title: this.formStory.title,
        }).then(() => {
          this.formStory = null;
        });
      } else if (this.formStory.newStory) {
        this.formStory = null;
      } else if (confirm('セパレートを削除しますか？')) {
        this.postAndFetchApi('delete.story', {
          story: this.formStory.story,
        }).then(() => {
          this.formStory = null;
        });
      }
    },

    operateCopyStory(story, newStory) {
      // セパレータを移動先に作る
      var lastStory = this.stories[this.stories.length - 1];
      var maxStory = +lastStory.pages[lastStory.pages.length - 1].page;
      var minStory = +this.stories[0].pages[0].page;
      if (newStory < minStory || maxStory < newStory) return Promise.reject({});
      return this.postApi('update.story', {
        story: newStory,
        title: story.title,
      });
    },
    operateMoveStory(story, toStory) {
      // セパレータを移動先に作ってから、古いものを削除する
      return this.operateCopyStory(story, toStory)
        .then(() => this.postApi('delete.story', {
          story: story.story,
        }));
    },
    operateDeletePage(page) {
      if (!page.delete_num) return Promise.reject({});
      // もしセパレータがあったら、次のページにセパレータを移してから削除
      var p = Promise.resolve({});
      // セパレータを動かす
      var story = this.stories[page.storyIndex];
      if (page.page === story.story && story.pages.length > 1) { // 自分が最後のページなら移さない
        p = p.then(() => this.operateCopyStory(story, 1 + (story.story - 0)).catch(() => {}));
      }
      return p.then(() => this.postApi('delete.page', { delete_num: page.delete_num }));
    },
    operateCreatePage(page) {
      var p = this.postApi('create.page', {
        after_page: page.page,
        text: page.data,
      });
      var curStory = this.stories[page.storyIndex];
      var oldStory = (curStory && page.page === curStory.story) ? curStory : null;
      if (oldStory) {
        // 常にセパレータの前にページは作られるので、moveStoryを後ろにズラす
        ++oldStory.story;
        p = p.then((data) => this.operateMoveStory(oldStory, oldStory.story - 1).then(() => data));
      }
      return p;
    },
    operateUpdatePage(page) {
      var p = this.operateCreatePage(page);
      if (!page.newPage) {
        // delete_numは変わらないので信じて消す
        p = p.then((data) => this.postApi('delete.page', { delete_num: page.delete_num }).then(() => data));
      }
      return p;
    },

    moveStory(story, delta) {
      var fromStory = +story.story;
      var toStory = fromStory + delta;
      this.operateMoveStory(story, toStory)
        .then(() => this.fetchData()); // スクロールもメッセージも不要
    },

    updatePage() {
      this.operateUpdatePage(this.formPage).then((data) => {
        this.mode = 'stories';
        return this.andFetchApi(data);
      });
    },
    deletePage() {
      if (!confirm('このページを本当に削除しますか？')) return;
      this.operateDeletePage(this.formPage).then(() => {
        this.mode = 'stories';
        return this.andFetchApi({ message: 'ページを消しました。' });
      }, (data) => {
        if (!data.error) alert('消せないっぽい');
      });
    },

    editStory(story) {
      this.formStory = clone(story);
    },
    newStoryBefore(page) {
      this.formStory = {
        newStory: true,
        story: page.page,
        title: '',
      };
    },
    editPage(page) {
      this.getApi('get.page', { page: page.page }).then((data) => {
        this.formPage = Object.assign({}, page, data);
        this.mode = 'page';
        this.updateData(this.mode);
      });
    },
    newPageBefore(page) {
      var nextStory = this.stories[page.storyIndex + 1];
      pp(page, nextStory);
      this.formPage = {
        type: 'text',
        data: '',
        page: 'page' in page ? page.page : nextStory ? nextStory.story : 0,
        storyIndex: page.storyIndex,
        newPage: true,
      };
      this.mode = 'page';
      this.updateData(this.mode);
    }
  },
  mounted() {
    this.$el.style.display = 'block';
  }
});

app.fetchData().then(() => {
  app.mode = 'stories';
  app.initialized = true;
}, () => {
  app.initialized = true;
});

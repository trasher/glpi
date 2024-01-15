const { defineConfig } = require("cypress");

module.exports = defineConfig({
    viewportWidth: 1920,
    viewportHeight: 1080,
    chromeWebSecurity: false,
    e2e: {
        baseUrl: "https://glpi.localhost:443",
        setupNodeEvents(on, config) {
            // implement node event listeners here
            // Remove --start-maximized flag from Chrome
            on("before:browser:launch", (browser = {}, launchOptions) => {
                const maximized_index = launchOptions.args.indexOf("--start-maximized");
                if (maximized_index !== -1) {
                    launchOptions.args.splice(maximized_index, 1);
                }
                return launchOptions;
            });
        },
    },
});

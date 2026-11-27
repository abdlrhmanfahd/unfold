import globals from "globals";

export default [
  {
    files: ["wp-content/plugins/unfold/assets/js/**/*.js"],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: "script",
      globals: {
        ...globals.browser,
        THREE: "readonly",
        Plotly: "readonly",
        mermaid: "readonly",
        katex: "readonly",
        renderMathInElement: "readonly",
        unfoldData: "readonly",
        unfoldPortal: "readonly",
        unfoldWorld: "readonly",
        unfoldWorlds: "readonly",
        unfoldSession: "readonly",
        unfoldForm: "readonly",
        unfoldAdmin: "readonly",
        wp: "readonly",
        jQuery: "readonly"
      }
    },
    rules: {
      "no-dupe-keys": "error",
      "no-duplicate-case": "error",
      "no-unreachable": "error",
      "no-const-assign": "error",
      "use-isnan": "error",
      "valid-typeof": "error",
      "no-debugger": "error",
      "no-func-assign": "error",
      "no-obj-calls": "error",
      "no-sparse-arrays": "error",
      "no-unsafe-negation": "error",
      "no-unused-vars": "warn"
    }
  }
];

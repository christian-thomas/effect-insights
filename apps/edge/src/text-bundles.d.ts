// Wrangler's [[rules]] with type = "Text" makes `.min.js` imports
// resolve to the file contents as a string at build time. TypeScript
// otherwise treats these as JS modules with no exports — this
// declaration tells tsc the shape so our `import axeSource from
// 'axe-core/axe.min.js'` typechecks cleanly.
declare module '*.min.js' {
  const content: string;
  export default content;
}

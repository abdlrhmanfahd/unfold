import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

test("project.schema.json is valid JSON with expected structure", () => {
  const raw = fs.readFileSync(
    path.resolve("wp-content/plugins/unfold/schema/project.schema.json"),
    "utf8"
  );
  const schema = JSON.parse(raw);
  assert.equal(typeof schema, "object");
  assert.ok(schema.$defs && typeof schema.$defs === "object", "schema.$defs should exist");
  assert.ok(Array.isArray(schema.required), "schema.required should be an array");
});

test("sample warehouse-fleet.json is valid JSON with required top-level keys", () => {
  const raw = fs.readFileSync(
    path.resolve("wp-content/plugins/unfold/sample/warehouse-fleet.json"),
    "utf8"
  );
  const sample = JSON.parse(raw);
  for (const key of ["schema_version", "meta", "goal", "subsystems"]) {
    assert.ok(key in sample, `sample JSON missing required key: ${key}`);
  }
  assert.ok(Array.isArray(sample.subsystems) && sample.subsystems.length > 0);
});

test("unfold-theme theme.json is valid JSON with version, settings, and styles", () => {
  const raw = fs.readFileSync(
    path.resolve("wp-content/themes/unfold-theme/theme.json"),
    "utf8"
  );
  const theme = JSON.parse(raw);
  assert.equal(typeof theme.version, "number");
  assert.ok(theme.settings && typeof theme.settings === "object");
  assert.ok(theme.styles && typeof theme.styles === "object");
});

test("unfold-theme templates and parts are non-empty and have balanced WP block comments", () => {
  const dirs = [
    path.resolve("wp-content/themes/unfold-theme/templates"),
    path.resolve("wp-content/themes/unfold-theme/parts"),
  ];

  const tagRe = /<!--\s*(\/?)wp:([a-z0-9-]+(?:\/[a-z0-9-]+)?)(?:\s+\{[\s\S]*?\})?\s*(\/?)-->/g;

  for (const dir of dirs) {
    const files = fs.readdirSync(dir).filter((f) => f.endsWith(".html"));
    assert.ok(files.length > 0, `Expected HTML files in ${dir}`);

    for (const file of files) {
      const fullPath = path.join(dir, file);
      const html = fs.readFileSync(fullPath, "utf8");
      assert.ok(html.trim().length > 0, `${file} should not be empty`);

      const stack = [];
      let match;
      tagRe.lastIndex = 0;
      while ((match = tagRe.exec(html)) !== null) {
        const [, isClosing, blockName, isSelfClosing] = match;
        if (isSelfClosing === "/") {
          continue;
        }
        if (isClosing === "/") {
          assert.ok(
            stack.length > 0,
            `${file}: unexpected closing <!-- /wp:${blockName} -->`
          );
          const expected = stack.pop();
          assert.equal(
            blockName,
            expected,
            `${file}: mismatched block tag (expected /wp:${expected}, got /wp:${blockName})`
          );
        } else {
          stack.push(blockName);
        }
      }
      assert.deepEqual(stack, [], `${file}: unclosed WP block tags: ${stack.join(", ")}`);
    }
  }
});

test("plugin CSS files have balanced braces", () => {
  const cssDir = path.resolve("wp-content/plugins/unfold/assets/css");
  const files = fs.readdirSync(cssDir).filter((f) => f.endsWith(".css"));
  assert.ok(files.length > 0, "Expected CSS files in assets/css");

  for (const file of files) {
    const raw = fs.readFileSync(path.join(cssDir, file), "utf8");
    let depth = 0;
    let inComment = false;
    let quote = null;

    for (let i = 0; i < raw.length; i += 1) {
      const ch = raw[i];
      const next = raw[i + 1];

      if (inComment) {
        if (ch === "*" && next === "/") {
          inComment = false;
          i += 1;
        }
        continue;
      }
      if (quote) {
        if (ch === "\\") {
          i += 1;
        } else if (ch === quote) {
          quote = null;
        }
        continue;
      }
      if (ch === "/" && next === "*") {
        inComment = true;
        i += 1;
        continue;
      }
      if (ch === "'" || ch === '"') {
        quote = ch;
        continue;
      }
      if (ch === "{") {
        depth += 1;
      } else if (ch === "}") {
        depth -= 1;
        assert.ok(depth >= 0, `${file}: unexpected closing brace '}' at index ${i}`);
      }
    }
    assert.equal(depth, 0, `${file}: unclosed '{' (brace depth ${depth})`);
  }
});

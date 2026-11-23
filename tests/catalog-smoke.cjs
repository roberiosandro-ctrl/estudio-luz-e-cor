"use strict";

const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const root = path.resolve(__dirname, "..");
const html = fs.readFileSync(path.join(root, "index-v2.html"), "utf8");
const catalogSource = fs.readFileSync(path.join(root, "catalog-data-v2.js"), "utf8");
const bootstrap = fs.readFileSync(path.join(root, "api/bootstrap.php"), "utf8");
const context = { window: {} };

vm.runInNewContext(catalogSource, context, { filename: "catalog-data-v2.js" });
const catalog = context.window.catalogData;

function check(condition, message) {
  if (!condition) {
    throw new Error("FALHOU: " + message);
  }
}

check(Array.isArray(catalog), "catálogo deve ser um array");
check(catalog.length === 10, "catálogo deve conter 10 coleções");

const models = catalog.flatMap((collection) => collection.models || []);
const codes = models.map((model) => model.code);
check(models.length === 60, "catálogo deve conter 60 modelos");
check(new Set(codes).size === 60, "todos os códigos do catálogo devem ser únicos");

const allowedPrefixes = ["ELEG", "PROF", "ROM", "LUX", "ANIV", "GEST", "CAS", "FASH", "FAM", "FANT"];
for (const code of codes) {
  check(/^[A-Z]+-0[1-6]$/.test(code), "formato de código inválido: " + code);
  check(allowedPrefixes.includes(code.split("-")[0]), "prefixo não permitido: " + code);
}
for (const model of models) {
  const imagePath = path.join(root, model.image);
  check(fs.existsSync(imagePath), "imagem ausente: " + model.image);
}
for (const prefix of allowedPrefixes) {
  check(bootstrap.includes("'" + prefix + "'"), "backend não permite o prefixo " + prefix);
}

const pricePairs = [
  ["02 FOTOS", "R$ 29,90"],
  ["05 FOTOS", "R$ 54,90"],
  ["10 FOTOS", "R$ 89,90"],
  ["15 FOTOS", "R$ 109,90"],
  ["20 FOTOS", "R$ 129,90"]
];
for (const [quantity, price] of pricePairs) {
  check(html.includes(quantity) && html.includes(price), "preço/quantidade ausente na página: " + quantity);
}

check(html.includes('src="catalog-data-v2.js"'), "página deve carregar os dados do catálogo");
check(html.includes('src="script-v2.js"'), "página deve carregar o script V2");
check(!html.includes("\\n"), "cabeçalho HTML não deve conter sequências de quebra escapadas literalmente");

console.log("Catalog and frontend smoke tests: OK");

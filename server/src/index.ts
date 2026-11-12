import { app } from "./app.js";
import { env } from "./config/env.js";

const server = app.listen(env.PORT, "0.0.0.0", () => {
  console.log(`Estúdio Luz e Cor API disponível na porta ${env.PORT}.`);
});

const shutdown = (signal: string) => {
  console.log(`Sinal ${signal} recebido. Encerrando servidor...`);
  server.close(() => {
    process.exit(0);
  });
};

process.on("SIGTERM", () => shutdown("SIGTERM"));
process.on("SIGINT", () => shutdown("SIGINT"));

import { resolve } from "node:path";
import cors from "cors";
import express, { type ErrorRequestHandler } from "express";
import rateLimit from "express-rate-limit";
import helmet from "helmet";
import { allowedOrigins, supabaseConfigured } from "./config/env.js";
import { supabaseAdmin } from "./config/supabase.js";

export const app = express();

app.disable("x-powered-by");
app.use(
  helmet({
    contentSecurityPolicy: {
      directives: {
        defaultSrc: ["'self'"],
        baseUri: ["'self'"],
        objectSrc: ["'none'"],
        frameAncestors: ["'none'"],
        formAction: ["'self'"],
        scriptSrc: ["'self'"],
        styleSrc: ["'self'", "'unsafe-inline'", "https://fonts.googleapis.com"],
        fontSrc: ["'self'", "https://fonts.gstatic.com"],
        imgSrc: ["'self'", "data:", "https://images.unsplash.com"],
        connectSrc: ["'self'"],
      },
    },
  }),
);
app.use(
  cors({
    origin(origin, callback) {
      // Permite ferramentas de servidor/terminal sem Origin; navegadores ficam
      // limitados à lista explícita configurada em CORS_ORIGINS.
      if (!origin || allowedOrigins.includes(origin)) {
        callback(null, true);
        return;
      }
      callback(null, false);
    },
    methods: ["GET", "POST", "PATCH", "OPTIONS"],
    allowedHeaders: ["Content-Type", "Authorization", "Idempotency-Key"],
  }),
);

app.use(express.json({ limit: "32kb" }));

const apiLimiter = rateLimit({
  windowMs: 60 * 1000,
  limit: 60,
  standardHeaders: "draft-7",
  legacyHeaders: false,
  message: {
    error: "Muitas solicitações em pouco tempo. Aguarde um instante e tente novamente.",
  },
});

app.use("/api", apiLimiter);

app.get("/api/health", async (_request, response) => {
  let database: "connected" | "not_configured" | "unavailable" = "not_configured";

  if (supabaseConfigured && supabaseAdmin) {
    const { error } = await supabaseAdmin
      .from("categories")
      .select("slug", { head: true, count: "exact" });

    if (error) {
      database = "unavailable";
      console.error("Verificação de conexão com Supabase falhou:", error.message);
    } else {
      database = "connected";
    }
  }

  response.status(database === "unavailable" ? 503 : 200).json({
    status: database === "unavailable" ? "degraded" : "ok",
    service: "estudio-luz-e-cor-api",
    timestamp: new Date().toISOString(),
    database,
    features: {
      orderSubmission: false,
      photoUpload: false,
      imageGeneration: false,
    },
  });
});

// Entrega somente os três arquivos públicos da interface.
// Não exponha a raiz do repositório com express.static, pois ela contém código
// do servidor e arquivos de configuração.
app.get("/", (_request, response, next) => {
  response.sendFile(resolve(process.cwd(), "index.html"), (error) => {
    if (error) next(error);
  });
});

app.get("/styles.css", (_request, response, next) => {
  response.sendFile(resolve(process.cwd(), "styles.css"), (error) => {
    if (error) next(error);
  });
});

app.get("/script.js", (_request, response, next) => {
  response.sendFile(resolve(process.cwd(), "script.js"), (error) => {
    if (error) next(error);
  });
});

app.use("/api", (_request, response) => {
  response.status(404).json({ error: "Rota da API não encontrada." });
});

app.use((_request, response) => {
  response.status(404).json({ error: "Rota não encontrada." });
});

const errorHandler: ErrorRequestHandler = (error, _request, response, _next) => {
  console.error("Erro inesperado na API:", error);
  response.status(500).json({
    error: "Ocorreu um erro interno. Tente novamente mais tarde.",
  });
};

app.use(errorHandler);

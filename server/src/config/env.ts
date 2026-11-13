import "dotenv/config";
import { z } from "zod";

const optionalSecret = z.preprocess(
  (value) => (value === "" ? undefined : value),
  z.string().min(1).optional(),
);

const envSchema = z.object({
  NODE_ENV: z.enum(["development", "test", "production"]).default("development"),
  PORT: z.coerce.number().int().min(1).max(65535).default(5000),
  CORS_ORIGINS: z.string().default("http://localhost:5000"),
  SUPABASE_URL: z.preprocess(
    (value) => (value === "" ? undefined : value),
    z.string().url().optional(),
  ),
  // Use a chave secreta do projeto somente no servidor. Nunca use a chave anon/public aqui.
  SUPABASE_SECRET_KEY: optionalSecret,
  // A geração por IA permanece desligada nesta etapa.
  OPENAI_ENABLED: z.literal("false").default("false"),
}).superRefine((value, context) => {
  const hasUrl = Boolean(value.SUPABASE_URL);
  const hasSecret = Boolean(value.SUPABASE_SECRET_KEY);

  if (hasUrl !== hasSecret) {
    context.addIssue({
      code: z.ZodIssueCode.custom,
      message: "Configure SUPABASE_URL e SUPABASE_SECRET_KEY juntas.",
      path: [hasUrl ? "SUPABASE_SECRET_KEY" : "SUPABASE_URL"],
    });
  }
});

const parsed = envSchema.safeParse(process.env);

if (!parsed.success) {
  console.error("Configuração inválida do servidor:", parsed.error.flatten().fieldErrors);
  throw new Error("Revise as variáveis de ambiente antes de iniciar o servidor.");
}

export const env = parsed.data;
export const allowedOrigins = env.CORS_ORIGINS
  .split(",")
  .map((origin) => origin.trim())
  .filter(Boolean);

export const supabaseConfigured = Boolean(
  env.SUPABASE_URL && env.SUPABASE_SECRET_KEY,
);

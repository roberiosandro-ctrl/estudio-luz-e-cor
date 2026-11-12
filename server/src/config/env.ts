import "dotenv/config";
import { z } from "zod";

const envSchema = z.object({
  NODE_ENV: z.enum(["development", "test", "production"]).default("development"),
  PORT: z.coerce.number().int().min(1).max(65535).default(5000),
  CORS_ORIGINS: z.string().default("http://localhost:5000"),
  // Enquanto o fluxo de aprovação e os controles de custo não estiverem prontos,
  // qualquer tentativa de ligar a geração por IA deve impedir a inicialização.
  OPENAI_ENABLED: z.literal("false").default("false"),
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

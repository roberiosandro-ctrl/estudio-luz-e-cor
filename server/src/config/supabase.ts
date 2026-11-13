import { createClient } from "@supabase/supabase-js";
import { env } from "./env.js";

// Cliente administrativo exclusivo do backend. Nunca importar no código do navegador.
export const supabaseAdmin =
  env.SUPABASE_URL && env.SUPABASE_SECRET_KEY
    ? createClient(env.SUPABASE_URL, env.SUPABASE_SECRET_KEY, {
        auth: {
          autoRefreshToken: false,
          persistSession: false,
          detectSessionInUrl: false,
        },
      })
    : null;

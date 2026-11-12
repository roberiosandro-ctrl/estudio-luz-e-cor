-- Estúdio Luz e Cor — estrutura inicial do banco
-- Execute somente em um projeto Supabase de desenvolvimento.
-- Não contém chaves, senhas ou dados reais de clientes.

begin;

create extension if not exists pgcrypto;

create table if not exists public.categories (
  slug text primary key,
  name text not null,
  description text not null default '',
  display_order integer not null unique,
  is_active boolean not null default true,
  created_at timestamptz not null default now(),
  constraint categories_slug_format check (slug ~ '^[a-z0-9-]+$'),
  constraint categories_name_not_empty check (length(trim(name)) > 0)
);

create table if not exists public.orders (
  id uuid primary key default gen_random_uuid(),
  idempotency_key uuid not null unique,
  category_slug text not null references public.categories(slug) on update cascade on delete restrict,
  customer_name varchar(120) not null,
  customer_email varchar(254),
  customer_phone varchar(30),
  customer_details varchar(3000) not null default '',
  status text not null default 'awaiting_review',
  privacy_accepted boolean not null default false,
  privacy_accepted_at timestamptz,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint orders_name_not_empty check (length(trim(customer_name)) between 2 and 120),
  constraint orders_email_format check (
    customer_email is null or customer_email ~* '^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}$'
  ),
  constraint orders_phone_length check (
    customer_phone is null or length(trim(customer_phone)) between 8 and 30
  ),
  constraint orders_status_check check (
    status in ('awaiting_review', 'approved', 'rejected', 'in_progress', 'completed', 'cancelled')
  ),
  constraint orders_privacy_timestamp check (
    (privacy_accepted = false and privacy_accepted_at is null)
    or (privacy_accepted = true and privacy_accepted_at is not null)
  )
);

create table if not exists public.admin_users (
  user_id uuid primary key references auth.users(id) on delete cascade,
  role text not null default 'operator',
  is_active boolean not null default true,
  created_at timestamptz not null default now(),
  constraint admin_users_role_check check (role in ('admin', 'operator'))
);

create table if not exists public.order_files (
  id uuid primary key default gen_random_uuid(),
  order_id uuid not null references public.orders(id) on delete cascade,
  bucket_id text not null,
  object_path text not null unique,
  file_role text not null default 'reference',
  original_filename varchar(255) not null,
  mime_type text not null,
  size_bytes bigint not null,
  position smallint not null default 1,
  sha256_hex char(64),
  created_at timestamptz not null default now(),
  constraint order_files_bucket_check check (bucket_id in ('fotos-referencia', 'fotos-geradas')),
  constraint order_files_role_check check (file_role in ('reference', 'generated')),
  constraint order_files_mime_check check (mime_type in ('image/jpeg', 'image/png', 'image/webp')),
  constraint order_files_size_check check (size_bytes between 1 and 10485760),
  constraint order_files_position_check check (position between 1 and 50),
  constraint order_files_hash_check check (sha256_hex is null or sha256_hex ~ '^[a-f0-9]{64}$')
);

create table if not exists public.generations (
  id uuid primary key default gen_random_uuid(),
  order_id uuid not null references public.orders(id) on delete cascade,
  status text not null default 'queued',
  provider text,
  model text,
  prompt_version text,
  error_code text,
  requested_by uuid references auth.users(id) on delete set null,
  started_at timestamptz,
  finished_at timestamptz,
  created_at timestamptz not null default now(),
  constraint generations_status_check check (status in ('queued', 'running', 'succeeded', 'failed', 'cancelled')),
  constraint generations_error_code_length check (error_code is null or length(error_code) <= 120),
  constraint generations_time_order check (
    finished_at is null or started_at is null or finished_at >= started_at
  )
);

create table if not exists public.status_history (
  id bigint generated always as identity primary key,
  order_id uuid not null references public.orders(id) on delete cascade,
  previous_status text,
  new_status text not null,
  changed_by uuid references auth.users(id) on delete set null,
  note varchar(1000),
  created_at timestamptz not null default now(),
  constraint status_history_new_status_check check (
    new_status in ('awaiting_review', 'approved', 'rejected', 'in_progress', 'completed', 'cancelled')
  ),
  constraint status_history_previous_status_check check (
    previous_status is null or previous_status in ('awaiting_review', 'approved', 'rejected', 'in_progress', 'completed', 'cancelled')
  )
);

-- Registra automaticamente a trilha de status sem expor permissão de INSERT
-- na tabela de histórico para usuários do navegador.
create or replace function public.record_order_status_change()
returns trigger
language plpgsql
security definer
set search_path = ''
as $
begin
  if tg_op = 'INSERT' then
    insert into public.status_history (order_id, previous_status, new_status, changed_by)
    values (new.id, null, new.status, (select auth.uid()));
  elsif old.status is distinct from new.status then
    insert into public.status_history (order_id, previous_status, new_status, changed_by)
    values (new.id, old.status, new.status, (select auth.uid()));
  end if;
  return new;
end;
$;

revoke all on function public.record_order_status_change() from public;

drop trigger if exists orders_record_status_change on public.orders;
create trigger orders_record_status_change
after insert or update of status on public.orders
for each row execute function public.record_order_status_change();

create index if not exists orders_status_created_idx
  on public.orders(status, created_at desc);
create index if not exists orders_category_created_idx
  on public.orders(category_slug, created_at desc);
create index if not exists order_files_order_idx
  on public.order_files(order_id, file_role, position);
create index if not exists generations_order_created_idx
  on public.generations(order_id, created_at desc);
create index if not exists status_history_order_created_idx
  on public.status_history(order_id, created_at desc);

create or replace function public.set_updated_at()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  new.updated_at = now();
  return new;
end;
$$;

drop trigger if exists orders_set_updated_at on public.orders;
create trigger orders_set_updated_at
before update on public.orders
for each row execute function public.set_updated_at();

-- SECURITY DEFINER evita recursão das políticas de RLS ao consultar admin_users.
create or replace function public.is_active_admin()
returns boolean
language sql
stable
security definer
set search_path = ''
as $$
  select exists (
    select 1
    from public.admin_users a
    where a.user_id = (select auth.uid())
      and a.is_active = true
  );
$$;

revoke all on function public.is_active_admin() from public;
grant execute on function public.is_active_admin() to authenticated;

alter table public.categories enable row level security;
alter table public.orders enable row level security;
alter table public.admin_users enable row level security;
alter table public.order_files enable row level security;
alter table public.generations enable row level security;
alter table public.status_history enable row level security;

drop policy if exists "Public can read active categories" on public.categories;
create policy "Public can read active categories"
  on public.categories for select
  to anon, authenticated
  using (is_active = true);

drop policy if exists "Admins can manage categories" on public.categories;
create policy "Admins can manage categories"
  on public.categories for all
  to authenticated
  using (public.is_active_admin())
  with check (public.is_active_admin());

drop policy if exists "Admins can read orders" on public.orders;
create policy "Admins can read orders"
  on public.orders for select
  to authenticated
  using (public.is_active_admin());

drop policy if exists "Admins can update orders" on public.orders;
create policy "Admins can update orders"
  on public.orders for update
  to authenticated
  using (public.is_active_admin())
  with check (public.is_active_admin());

drop policy if exists "Admins can read admin users" on public.admin_users;
create policy "Admins can read admin users"
  on public.admin_users for select
  to authenticated
  using (public.is_active_admin());

drop policy if exists "Admins can read order files" on public.order_files;
create policy "Admins can read order files"
  on public.order_files for select
  to authenticated
  using (public.is_active_admin());

drop policy if exists "Admins can read generations" on public.generations;
create policy "Admins can read generations"
  on public.generations for select
  to authenticated
  using (public.is_active_admin());

drop policy if exists "Admins can read status history" on public.status_history;
create policy "Admins can read status history"
  on public.status_history for select
  to authenticated
  using (public.is_active_admin());

-- Privilégios explícitos: o navegador só lê categorias e administradores
-- autenticados consultam/atualizam o que as políticas RLS permitem.
revoke all on public.categories, public.orders, public.admin_users,
  public.order_files, public.generations, public.status_history
  from anon, authenticated;

grant select on public.categories to anon, authenticated;
grant select, update on public.orders to authenticated;
grant select on public.admin_users, public.order_files,
  public.generations, public.status_history to authenticated;

grant all on public.categories, public.orders, public.admin_users,
  public.order_files, public.generations, public.status_history to service_role;
grant usage, select on all sequences in schema public to service_role;

-- Buckets privados. O backend usará a chave service_role somente no servidor
-- e URLs assinadas curtas para compartilhar arquivos autorizados.
insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values
  ('fotos-referencia', 'fotos-referencia', false, 10485760, array['image/jpeg', 'image/png', 'image/webp']),
  ('fotos-geradas', 'fotos-geradas', false, 10485760, array['image/jpeg', 'image/png', 'image/webp'])
on conflict (id) do update
set public = excluded.public,
    file_size_limit = excluded.file_size_limit,
    allowed_mime_types = excluded.allowed_mime_types;

drop policy if exists "Admins can read private studio images" on storage.objects;
create policy "Admins can read private studio images"
  on storage.objects for select
  to authenticated
  using (
    bucket_id in ('fotos-referencia', 'fotos-geradas')
    and public.is_active_admin()
  );

drop policy if exists "Admins can upload private studio images" on storage.objects;
create policy "Admins can upload private studio images"
  on storage.objects for insert
  to authenticated
  with check (
    bucket_id in ('fotos-referencia', 'fotos-geradas')
    and public.is_active_admin()
  );

drop policy if exists "Admins can update private studio images" on storage.objects;
create policy "Admins can update private studio images"
  on storage.objects for update
  to authenticated
  using (
    bucket_id in ('fotos-referencia', 'fotos-geradas')
    and public.is_active_admin()
  )
  with check (
    bucket_id in ('fotos-referencia', 'fotos-geradas')
    and public.is_active_admin()
  );

drop policy if exists "Admins can delete private studio images" on storage.objects;
create policy "Admins can delete private studio images"
  on storage.objects for delete
  to authenticated
  using (
    bucket_id in ('fotos-referencia', 'fotos-geradas')
    and public.is_active_admin()
  );

insert into public.categories (slug, name, description, display_order)
values
  ('paisagens', 'Paisagens e natureza', 'Cenários ao ar livre e destinos especiais.', 1),
  ('casamento', 'Casamento e noivado', 'Registros românticos para momentos únicos.', 2),
  ('namorados', 'Dia dos Namorados', 'Uma lembrança criativa para celebrar a dois.', 3),
  ('aniversario', 'Aniversário', 'Um ensaio especial para celebrar sua data.', 4),
  ('feminino', 'Ensaio feminino', 'Beleza, atitude e personalidade em cada foto.', 5),
  ('masculino', 'Ensaio masculino', 'Estilo e presença em imagens marcantes.', 6),
  ('casal', 'Ensaio de casal', 'Fotos que contam a história de vocês.', 7),
  ('profissional', 'Ensaio profissional', 'Uma imagem alinhada à sua marca pessoal.', 8),
  ('praia', 'Praia e verão', 'Leveza, sol e clima de férias.', 9),
  ('natal', 'Natal', 'Um clima especial para guardar de lembrança.', 10),
  ('ano-novo', 'Ano Novo', 'Comece um novo ciclo com fotos especiais.', 11),
  ('familia', 'Família', 'Momentos e conexões que merecem ser lembrados.', 12),
  ('moda', 'Moda e luxo', 'Produções sofisticadas e cheias de estilo.', 13),
  ('gestante', 'Gestante', 'Uma fase única registrada com delicadeza.', 14),
  ('infantil', 'Infantil', 'Imaginação e alegria em cada detalhe.', 15)
on conflict (slug) do update
set name = excluded.name,
    description = excluded.description,
    display_order = excluded.display_order,
    is_active = true;

commit;

# Estúdio Luz e Cor

Plataforma para solicitar ensaios fotográficos personalizados com inteligência artificial.

## Estado desta branch

Esta é a branch de desenvolvimento do MVP. A branch `main` e o site atualmente publicado não devem ser alterados por este trabalho até que a versão seja revisada e aprovada.

## O que existe nesta etapa

- Página inicial responsiva com identidade visual do Estúdio Luz e Cor.
- Catálogo visual com 15 categorias de ensaios.
- Busca por texto e filtros por grupo.
- Seleção de categoria que preenche o formulário demonstrativo.
- Formulário de prévia, sem envio ou armazenamento de dados.
- Campo de upload desativado enquanto o armazenamento seguro não estiver conectado.

## Categorias

Paisagens e natureza, casamento e noivado, Dia dos Namorados, aniversário, ensaio feminino, ensaio masculino, ensaio de casal, ensaio profissional, praia e verão, Natal, Ano Novo, família, moda e luxo, gestante e infantil.

## Próximas etapas planejadas

1. Revisar visualmente o catálogo em desktop e celular.
2. Criar backend Node.js e validação de pedidos.
3. Configurar Supabase/PostgreSQL e armazenamento privado.
4. Implementar autenticação e painel administrativo.
5. Implementar upload seguro de 1 a 5 imagens por pedido.
6. Preparar integração de geração com IA desativada por padrão.
7. Testar em ambiente separado antes de qualquer publicação.

## Segurança e limitações atuais

- Não há backend, banco de dados ou painel administrativo nesta etapa.
- O formulário não registra pedidos nem envia mensagens.
- O upload de fotos está desativado.
- Não há integração ativa com OpenAI.
- Não colocar chaves, senhas ou arquivos `.env` neste repositório.
- Imagens do catálogo são referências ilustrativas e não devem ser apresentadas como resultados de clientes.

## Publicação

Não implantar esta branch no domínio de produção sem revisão e autorização explícita.

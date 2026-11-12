document.getElementById("year").textContent = new Date().getFullYear();

const cards = Array.from(document.querySelectorAll(".category-card"));
const filterButtons = Array.from(document.querySelectorAll(".filter-button"));
const searchInput = document.getElementById("categorySearch");
const emptyMessage = document.getElementById("catalogEmpty");
const categorySelect = document.getElementById("style");
const form = document.getElementById("orderForm");
const feedback = document.getElementById("formFeedback");

let activeFilter = "todos";

function filterCatalog() {
  const query = searchInput.value.trim().toLocaleLowerCase("pt-BR");
  let visibleCount = 0;

  cards.forEach((card) => {
    const matchesFilter = activeFilter === "todos" || card.dataset.group === activeFilter;
    const searchableText = (card.dataset.search + " " + card.textContent).toLocaleLowerCase("pt-BR");
    const matchesSearch = !query || searchableText.includes(query);
    const visible = matchesFilter && matchesSearch;

    card.hidden = !visible;
    if (visible) visibleCount += 1;
  });

  emptyMessage.hidden = visibleCount > 0;
}

filterButtons.forEach((button) => {
  button.addEventListener("click", () => {
    activeFilter = button.dataset.filter;
    filterButtons.forEach((item) => {
      const selected = item === button;
      item.classList.toggle("is-active", selected);
      item.setAttribute("aria-pressed", String(selected));
    });
    filterCatalog();
  });
});

searchInput.addEventListener("input", filterCatalog);

document.querySelectorAll("[data-category]").forEach((button) => {
  button.addEventListener("click", () => {
    categorySelect.value = button.dataset.category;
    feedback.textContent = "Categoria selecionada. Complete os dados para visualizar a prévia.";
    document.getElementById("pedido").scrollIntoView({ behavior: "smooth" });
    document.getElementById("name").focus({ preventScroll: true });
  });
});

form.addEventListener("submit", (event) => {
  event.preventDefault();
  const data = new FormData(form);
  const name = String(data.get("name") || "").trim();
  const style = categorySelect.options[categorySelect.selectedIndex]?.text || "";

  if (!name || !categorySelect.value) {
    feedback.textContent = "Preencha seu nome e escolha uma categoria para continuar.";
    return;
  }

  feedback.textContent = "Prévia pronta, " + name + "! Categoria: " + style + ". Este formulário ainda não envia nem registra pedidos. A integração segura será feita na próxima etapa.";
});
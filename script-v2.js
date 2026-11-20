(() => {
  "use strict";

  const catalogRoot = document.getElementById("catalogo");
  const selectionPanel = document.getElementById("selectionPanel");
  const selectionList = document.getElementById("selectionList");
  const selectionCount = document.getElementById("selectionCount");
  const openSelection = document.getElementById("openSelection");
  const closeSelection = document.getElementById("closeSelection");
  const clearSelection = document.getElementById("clearSelection");
  const requestButton = document.getElementById("requestButton");
  const lightbox = document.getElementById("lightbox");
  const lightboxImage = document.getElementById("lightboxImage");
  const lightboxCode = document.getElementById("lightboxCode");
  const lightboxTitle = document.getElementById("lightboxTitle");
  const closeLightbox = document.getElementById("closeLightbox");
  const toast = document.getElementById("toast");

  const catalog = Array.isArray(window.catalogData) ? window.catalogData : [];
  const selected = new Map();
  let toastTimer;

  const escapeHtml = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
  })[char]);

  function showToast(message) {
    if (!toast) return;
    toast.textContent = message;
    toast.classList.add("is-visible");
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(() => toast.classList.remove("is-visible"), 3200);
  }

  function setPanel(open) {
    if (!selectionPanel) return;
    selectionPanel.classList.toggle("is-open", open);
    selectionPanel.setAttribute("aria-hidden", String(!open));
    if (open) {
      selectionPanel.removeAttribute("inert");
      openSelection?.setAttribute("aria-expanded", "true");
      closeSelection?.focus();
    } else {
      selectionPanel.setAttribute("inert", "");
      openSelection?.setAttribute("aria-expanded", "false");
      openSelection?.focus();
    }
  }

  function setLightbox(open) {
    if (!lightbox) return;
    lightbox.classList.toggle("is-open", open);
    lightbox.setAttribute("aria-hidden", String(!open));
    document.body.classList.toggle("modal-open", open);
    if (open) closeLightbox?.focus();
  }

  function renderCatalog() {
    if (!catalogRoot) return;
    if (!catalog.length) {
      catalogRoot.innerHTML = '<p class="catalog-error">Não foi possível carregar o catálogo agora. Atualize a página para tentar novamente.</p>';
      return;
    }

    const fragment = document.createDocumentFragment();

    catalog.forEach((collection, index) => {
      const section = document.createElement("section");
      section.className = "collection-section";
      section.id = collection.id || `colecao-${index + 1}`;

      const heading = document.createElement("div");
      heading.className = "collection-heading";
      const copy = document.createElement("div");
      copy.className = "collection-heading-copy";

      const eyebrow = document.createElement("p");
      eyebrow.className = "kicker";
      eyebrow.textContent = collection.eyebrow || collection.code || `COLEÇÃO ${String(index + 1).padStart(2, "0")}`;

      const title = document.createElement("h2");
      title.textContent = collection.name || "Coleção";

      const description = document.createElement("p");
      description.className = "collection-description";
      description.textContent = collection.description || "Escolha as referências que combinam com você.";

      copy.append(eyebrow, title, description);
      const quantity = document.createElement("span");
      quantity.className = "collection-count";
      quantity.textContent = `${Array.isArray(collection.models) ? collection.models.length : 0} estilos`;
      heading.append(copy, quantity);

      const grid = document.createElement("div");
      grid.className = "model-grid";

      (Array.isArray(collection.models) ? collection.models : []).forEach((model) => {
        const card = document.createElement("article");
        card.className = "model-card";
        card.dataset.code = model.code || "";

        const imageButton = document.createElement("button");
        imageButton.className = "model-image-button";
        imageButton.type = "button";
        imageButton.dataset.action = "preview";
        imageButton.setAttribute("aria-label", `Ampliar ${model.title || model.code || "modelo"}`);

        const image = document.createElement("img");
        image.src = model.image || "";
        image.alt = `${model.title || model.code || "Referência"} — ${collection.name || "Estúdio Luz e Cor"}`;
        image.loading = "lazy";
        image.decoding = "async";
        image.addEventListener("error", () => {
          imageButton.classList.add("image-unavailable");
          imageButton.setAttribute("aria-label", "Imagem indisponível");
          image.remove();
          const placeholder = document.createElement("span");
          placeholder.className = "image-placeholder";
          placeholder.textContent = "Imagem indisponível";
          imageButton.append(placeholder);
        }, { once: true });
        imageButton.append(image);

        const info = document.createElement("div");
        info.className = "model-info";
        const code = document.createElement("span");
        code.className = "model-code";
        code.textContent = model.code || "";
        const modelTitle = document.createElement("h3");
        modelTitle.textContent = model.title || "Modelo";
        const modelDescription = document.createElement("p");
        modelDescription.textContent = model.description || "";
        info.append(code, modelTitle, modelDescription);

        const selectButton = document.createElement("button");
        selectButton.className = "model-select";
        selectButton.type = "button";
        selectButton.dataset.action = "select";
        selectButton.textContent = "＋ Adicionar à seleção";
        selectButton.setAttribute("aria-pressed", "false");

        card.append(imageButton, info, selectButton);
        grid.append(card);
      });

      section.append(heading, grid);
      fragment.append(section);
    });

    catalogRoot.replaceChildren(fragment);
  }

  function renderSelection() {
    if (!selectionList || !selectionCount) return;
    selectionCount.textContent = String(selected.size);
    openSelection?.setAttribute("aria-label", `Minha seleção: ${selected.size} modelos`);

    if (!selected.size) {
      selectionList.innerHTML = '<p class="empty-selection">Você ainda não selecionou um modelo.</p>';
      clearSelection && (clearSelection.disabled = true);
      requestButton && (requestButton.disabled = true);
      return;
    }

    clearSelection && (clearSelection.disabled = false);
    requestButton && (requestButton.disabled = false);
    const fragment = document.createDocumentFragment();

    selected.forEach((model) => {
      const item = document.createElement("div");
      item.className = "selection-item";
      const copy = document.createElement("div");
      const code = document.createElement("span");
      code.className = "model-code";
      code.textContent = model.code;
      const title = document.createElement("strong");
      title.textContent = model.title;
      const collection = document.createElement("small");
      collection.textContent = model.collection;
      copy.append(code, title, collection);

      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "remove-selection";
      remove.dataset.action = "remove";
      remove.dataset.code = model.code;
      remove.setAttribute("aria-label", `Remover ${model.code} da seleção`);
      remove.textContent = "×";
      item.append(copy, remove);
      fragment.append(item);
    });

    selectionList.replaceChildren(fragment);
  }

  function toggleSelection(model, collectionName) {
    if (!model?.code) return;
    if (selected.has(model.code)) {
      selected.delete(model.code);
      showToast(`${model.code} removido da seleção.`);
    } else {
      selected.set(model.code, {
        code: model.code,
        title: model.title || model.code,
        collection: collectionName || "Coleção"
      });
      showToast(`${model.code} adicionado à seleção.`);
    }

    document.querySelectorAll(".model-card").forEach((card) => {
      if (card.dataset.code !== model.code) return;
      const button = card.querySelector('[data-action="select"]');
      const active = selected.has(model.code);
      button?.classList.toggle("is-selected", active);
      button?.setAttribute("aria-pressed", String(active));
      if (button) button.textContent = active ? "✓ Adicionado" : "＋ Adicionar à seleção";
    });
    renderSelection();
  }

  function findModel(code) {
    for (const collection of catalog) {
      const model = (collection.models || []).find((item) => item.code === code);
      if (model) return { model, collection };
    }
    return null;
  }

  catalogRoot?.addEventListener("click", (event) => {
    const button = event.target.closest("button[data-action]");
    if (!button) return;
    const card = button.closest(".model-card");
    const found = findModel(card?.dataset.code);
    if (!found) return;

    if (button.dataset.action === "select") {
      toggleSelection(found.model, found.collection.name);
    } else if (button.dataset.action === "preview") {
      lightboxImage.src = found.model.image || "";
      lightboxImage.alt = `${found.model.title || found.model.code} — ${found.collection.name}`;
      lightboxCode.textContent = found.model.code || "";
      lightboxTitle.textContent = found.model.title || "";
      setLightbox(true);
    }
  });

  selectionList?.addEventListener("click", (event) => {
    const button = event.target.closest('[data-action="remove"]');
    if (!button) return;
    const found = findModel(button.dataset.code);
    if (found) toggleSelection(found.model, found.collection.name);
  });

  openSelection?.addEventListener("click", () => setPanel(true));
  closeSelection?.addEventListener("click", () => setPanel(false));
  clearSelection?.addEventListener("click", () => {
    if (!selected.size) return;
    selected.clear();
    document.querySelectorAll(".model-select").forEach((button) => {
      button.classList.remove("is-selected");
      button.setAttribute("aria-pressed", "false");
      button.textContent = "＋ Adicionar à seleção";
    });
    renderSelection();
    showToast("Sua seleção foi limpa.");
  });

  requestButton?.addEventListener("click", () => {
    if (!selected.size) return;

    const lines = Array.from(selected.values()).map((model) =>
      `• ${model.code} — ${model.title} (${model.collection})`
    );
    const message = [
      "Olá! Gostaria de solicitar atendimento no Estúdio Luz e Cor.",
      "",
      "Estes são os estilos que selecionei:",
      ...lines,
      "",
      "Podem me passar as informações dos pacotes e valores?"
    ].join("\n");
    const whatsappUrl = `https://wa.me/5548998336779?text=${encodeURIComponent(message)}`;
    const whatsappWindow = window.open(whatsappUrl, "_blank", "noopener,noreferrer");

    if (!whatsappWindow) {
      window.location.href = whatsappUrl;
    }
  });

  closeLightbox?.addEventListener("click", () => setLightbox(false));
  lightbox?.addEventListener("click", (event) => {
    if (event.target === lightbox) setLightbox(false);
  });

  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") return;
    if (lightbox?.classList.contains("is-open")) setLightbox(false);
    else if (selectionPanel?.classList.contains("is-open")) setPanel(false);
  });

  setPanel(false);
  renderCatalog();
  renderSelection();
})();

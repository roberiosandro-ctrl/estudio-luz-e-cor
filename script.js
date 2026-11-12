document.getElementById("year").textContent = new Date().getFullYear();

const form = document.getElementById("orderForm");
const feedback = document.getElementById("formFeedback");

form.addEventListener("submit", (event) => {
  event.preventDefault();
  const data = new FormData(form);
  const name = String(data.get("name") || "").trim();
  const style = String(data.get("style") || "").trim();

  if (!name || !style) {
    feedback.textContent = "Preencha seu nome e escolha um estilo para continuar.";
    return;
  }

  feedback.textContent = "Obrigado, " + name + "! Esta é uma prévia do formulário. O recebimento de pedidos e fotos será conectado na próxima etapa, antes de publicar o site para clientes.";
});

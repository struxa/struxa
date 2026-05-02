(() => {
  const tips = [
    { time: "14:20", meeting: "York", horse: "Royal Tribute", tip: "WIN", price: "3.50", silk: "silk-green" },
    { time: "15:00", meeting: "Newmarket", horse: "Desert Prince", tip: "WIN", price: "4.00", silk: "silk-blue" },
    { time: "15:35", meeting: "Goodwood", horse: "Silent Whisper", tip: "E/W", price: "6.50", silk: "silk-red" },
    { time: "16:10", meeting: "York", horse: "Bold Endeavour", tip: "WIN", price: "5.00", silk: "silk-green" },
    { time: "16:45", meeting: "Newmarket", horse: "Lightning Bay", tip: "E/W", price: "7.50", silk: "silk-purple" },
  ];

  function renderTips() {
    const tipsBody = document.getElementById("hrTipsBody");
    if (!tipsBody) return;
    tipsBody.innerHTML = tips
      .map(
        (row, index) => `
      <tr>
        <td>${row.time}</td>
        <td>${row.meeting}</td>
        <td><div class="horse-cell"><span class="silks ${row.silk}">${index + 1}</span>${row.horse}</div></td>
        <td><span class="tag">${row.tip}</span></td>
        <td>${row.price}</td>
        <td><button type="button" class="bet-btn">BET NOW</button></td>
      </tr>`,
      )
      .join("");
    tipsBody.querySelectorAll(".bet-btn").forEach((button) => {
      button.addEventListener("click", () => {
        button.textContent = "SELECTED";
        button.style.opacity = "0.8";
      });
    });
  }

  function animateCounters() {
    document.querySelectorAll("[data-count]").forEach((el) => {
      const target = parseFloat(el.dataset.count);
      if (Number.isNaN(target)) return;
      const label = el.parentElement ? el.parentElement.innerText.toLowerCase() : "";
      const isPercent =
        label.includes("rate") || label.includes("roi") || label.includes("strike");
      let current = 0;
      const steps = 38;
      const increment = target / steps;
      const timer = setInterval(() => {
        current += increment;
        if (current >= target) {
          current = target;
          clearInterval(timer);
        }
        const value = Number.isInteger(target) ? Math.round(current) : current.toFixed(1);
        el.textContent = isPercent ? (target === 28.7 ? `+${value}%` : `${value}%`) : String(value);
      }, 24);
    });
  }

  function drawChart(points) {
    const linePath = document.getElementById("hrLinePath");
    const areaPath = document.getElementById("hrAreaPath");
    if (!linePath || !areaPath || !points.length) return;
    const width = 520;
    const height = 160;
    const min = Math.min(...points) - 4;
    const max = Math.max(...points) + 4;
    const step = width / (points.length - 1);
    const coords = points.map((p, i) => {
      const x = i * step;
      const y = height - ((p - min) / (max - min)) * (height - 24) - 12;
      return [x, y];
    });
    const line = coords.map((c, i) => `${i === 0 ? "M" : "L"} ${c[0].toFixed(1)} ${c[1].toFixed(1)}`).join(" ");
    const area = `${line} L ${width} ${height} L 0 ${height} Z`;
    linePath.setAttribute("d", line);
    areaPath.setAttribute("d", area);
  }

  const dataSets = {
    12: { roi: "+28.7%", points: [2, 6, 4, 10, 7, 13, 11, 18, 14, 19, 16, 23, 21, 27, 25, 31, 28, 35, 32, 39, 37, 44] },
    6: { roi: "+18.4%", points: [1, 4, 2, 8, 6, 10, 13, 9, 15, 18, 16, 22] },
    3: { roi: "+9.2%", points: [1, 2, 1.5, 4, 3, 6, 5, 8, 7, 10] },
  };

  function bindRangeSelect() {
    const sel = document.getElementById("hrRangeSelect");
    const roi = document.getElementById("hrRoiValue");
    if (!sel || !roi) return;
    sel.addEventListener("change", () => {
      const selected = dataSets[sel.value];
      if (!selected) return;
      roi.textContent = selected.roi;
      drawChart(selected.points);
    });
  }

  function bindMobileNav() {
    const toggle = document.getElementById("hrMobileToggle");
    const nav = document.getElementById("hrNavLinks");
    if (!toggle || !nav) return;
    toggle.addEventListener("click", () => {
      nav.classList.toggle("is-open");
      const open = nav.classList.contains("is-open");
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
    });
  }

  renderTips();
  bindRangeSelect();
  bindMobileNav();
  animateCounters();
  if (dataSets[12] && dataSets[12].points.length) {
    drawChart(dataSets[12].points);
  }
})();

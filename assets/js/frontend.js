(function () {
  "use strict";

  if (typeof window.nationalGridFrontend === "undefined") {
    return;
  }

  // Localized runtime settings injected by wp_localize_script.
  var config = window.nationalGridFrontend;
  // Controls Chart.js animation for pie/bar charts.
  var CHART_ANIMATION = Number(config.chartAnimation) === 1 ? {} : false;
  // All frontend widget instances on the current page.
  var widgets = document.querySelectorAll(".national-grid-frontend");
  if (!widgets.length) {
    return;
  }

  // Display order for pie slices and legend entries.
  var COMPONENT_ORDER = [
    "Gas",
    "Wind",
    "Solar",
    "Hydroelectric",
    "Nuclear",
    "Biomass",
    "Interconnectors",
    "Storage",
  ];

  // Source labels included in the clean power percentage metric.
  var CLEAN_POWER_COMPONENTS = ["Wind", "Solar", "Hydroelectric", "Biomass", "Nuclear"];

  // Category definitions used to build grouped stacked bars.
  var BAR_GROUPS = [
    { label: "Renewable", components: ["Wind", "Solar", "Hydroelectric"] },
    { label: "Other Low Carbon", components: ["Biomass", "Nuclear"] },
    { label: "Fossil Fuels", components: ["Gas"] },
    { label: "Other", components: ["Interconnectors", "Storage"] },
  ];

  // Minimum share of a stacked bar segment required to render inline label.
  var BAR_LABEL_MIN_PERCENT = 20;

  // Fallback palette when no explicit color mapping exists.
  var palette = [
    "#1b4965",
    "#2a9d8f",
    "#e76f51",
    "#264653",
    "#f4a261",
    "#457b9d",
    "#6d597a",
    "#8ab17d",
  ];

  // Canonical color mapping for known generation labels.
  var colorByLabel = {
    Gas: "#9E8B73",
    Wind: "#5FC79A",
    Solar: "#F4C84A",
    Hydroelectric: "#4F93D1",
    Nuclear: "#9A6BC7",
    Biomass: "#7EAF2F",
    Interconnectors: "#E0953C",
    Storage: "#556A85",
  };

  // Draws percentage labels directly on pie slices.
  var piePercentLabelsPlugin = {
    id: "nationalGridPiePercentLabels",
    afterDatasetsDraw: function (chart) {
      var dataset = chart.data && chart.data.datasets ? chart.data.datasets[0] : null;
      if (!dataset || !Array.isArray(dataset.data) || !dataset.data.length) {
        return;
      }

      var total = getDatasetTotal(dataset);
      if (total <= 0) {
        return;
      }

      var ctx = chart.ctx;
      var meta = chart.getDatasetMeta(0);
      ctx.save();
      ctx.font = "600 12px sans-serif";
      ctx.fillStyle = "#102a43";
      ctx.textAlign = "center";
      ctx.textBaseline = "middle";

      meta.data.forEach(function (arc, index) {
        var value = Number(dataset.data[index]);
        if (!Number.isFinite(value) || value <= 0) {
          return;
        }

        var percent = (value / total) * 100;
        if ( percent < 5 ) {
          return;
        }

        var midAngle = (arc.startAngle + arc.endAngle) / 2;
        var labelRadius = arc.outerRadius * 0.72;
        var x = arc.x + Math.cos(midAngle) * labelRadius;
        var y = arc.y + Math.sin(midAngle) * labelRadius;
        ctx.fillText(formatPercent(percent) + "%", x, y);
      });

      ctx.restore();
    },
  };

  // Draws a white center with total generation value inside the pie.
  var pieCenterTotalPlugin = {
    id: "nationalGridPieCenterTotal",
    afterDatasetsDraw: function (chart) {
      var dataset = chart.data && chart.data.datasets ? chart.data.datasets[0] : null;
      var meta = chart.getDatasetMeta(0);
      if (!dataset || !meta || !meta.data || !meta.data.length) {
        return;
      }

      var total = getDatasetTotal(dataset);
      var arc = meta.data[0];
      if (!arc || typeof arc.x !== "number" || typeof arc.y !== "number") {
        return;
      }

      var centerX = arc.x;
      var centerY = arc.y;
      var outerRadius = typeof arc.outerRadius === "number" ? arc.outerRadius : 0;
      if (outerRadius <= 0) {
        return;
      }

      var innerCircleRadius = outerRadius * 0.52;
      var ctx = chart.ctx;
      ctx.save();
      ctx.beginPath();
      ctx.arc(centerX, centerY, innerCircleRadius, 0, Math.PI * 2);
      ctx.fillStyle = "#ffffff";
      ctx.fill();

      ctx.fillStyle = "#334e68";
      ctx.textAlign = "center";
      ctx.textBaseline = "middle";
      ctx.font = "700 14px sans-serif";
      ctx.fillText("Total Generation", centerX, centerY - 10);

      ctx.fillStyle = "#102a43";
      ctx.font = "700 14px sans-serif";
      ctx.fillText(total.toFixed(1) + " GW", centerX, centerY + 10);
      ctx.restore();
    },
  };

  // Draws GW labels centered on visible stacked bar segments.
  var barSegmentLabelsPlugin = {
    id: "nationalGridBarSegmentLabels",
    afterDatasetsDraw: function (chart) {
      if (!chart || chart.config.type !== "bar") {
        return;
      }

      var ctx = chart.ctx;
      ctx.save();
      ctx.textAlign = "center";
      ctx.textBaseline = "middle";
      ctx.fillStyle = "#102a43";
      ctx.font = "600 11px sans-serif";

      chart.data.datasets.forEach(function (dataset, datasetIndex) {
        var meta = chart.getDatasetMeta(datasetIndex);
        if (!meta || meta.hidden) {
          return;
        }

        meta.data.forEach(function (bar, index) {
          var value = Number(dataset.data[index]);
          if (!Number.isFinite(value) || value <= 0) {
            return;
          }

          var stackTotal = chart.data.datasets.reduce(function (sum, ds, dsIndex) {
            var dsMeta = chart.getDatasetMeta(dsIndex);
            if (!dsMeta || dsMeta.hidden) {
              return sum;
            }

            var segmentValue = Number(ds.data[index]);
            return Number.isFinite(segmentValue) && segmentValue > 0 ? sum + segmentValue : sum;
          }, 0);
          var segmentPercent = stackTotal > 0 ? (value / stackTotal) * 100 : 0;
          if (segmentPercent < BAR_LABEL_MIN_PERCENT) {
            return;
          }

          var barHeight = Math.abs(bar.base - bar.y);
          ctx.fillText(value.toFixed(1) + " GW", bar.x, bar.y + barHeight / 2);
        });
      });

      ctx.restore();
    },
  };

  // Escapes user-facing string fragments used in custom tooltip HTML.
  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  // Returns stacked total for one X-axis category index.
  function getBarCategoryTotal(chart, categoryIndex) {
    if (!chart || !chart.data || !Array.isArray(chart.data.datasets)) {
      return 0;
    }

    return chart.data.datasets.reduce(function (sum, dataset) {
      var value = dataset && Array.isArray(dataset.data) ? Number(dataset.data[categoryIndex]) : 0;
      return Number.isFinite(value) && value > 0 ? sum + value : sum;
    }, 0);
  }

  // Returns total generation across all grouped bar categories.
  function getBarTotalGeneration(chart) {
    if (!chart || !chart.data || !Array.isArray(chart.data.datasets)) {
      return 0;
    }

    return chart.data.datasets.reduce(function (sum, dataset) {
      if (!dataset || !Array.isArray(dataset.data)) {
        return sum;
      }

      return (
        sum +
        dataset.data.reduce(function (datasetSum, value) {
          var numeric = Number(value);
          return Number.isFinite(numeric) && numeric > 0 ? datasetSum + numeric : datasetSum;
        }, 0)
      );
    }, 0);
  }

  // Creates an HTML tooltip node for bar X-axis label hover details.
  function ensureBarAxisTooltipNode(widget) {
    if (!widget) {
      return null;
    }

    var existing = widget.querySelector(".national-grid-frontend-axis-tooltip");
    if (existing) {
      return existing;
    }

    var tooltipNode = document.createElement("div");
    tooltipNode.className = "national-grid-frontend-axis-tooltip";
    tooltipNode.style.position = "absolute";
    tooltipNode.style.pointerEvents = "none";
    tooltipNode.style.zIndex = "20";
    tooltipNode.style.display = "none";
    tooltipNode.style.minWidth = "300px";
    tooltipNode.style.maxWidth = "300px";
    tooltipNode.style.padding = "6px";
    tooltipNode.style.background = "rgba(0, 0, 0, 0.8)";
    tooltipNode.style.color = "#ffffff";
    tooltipNode.style.borderRadius = "6px";
    tooltipNode.style.fontSize = "12px";
    tooltipNode.style.lineHeight = "1.4";
    tooltipNode.style.whiteSpace = "nowrap";
    tooltipNode.style.boxShadow = "none";

    if (window.getComputedStyle(widget).position === "static") {
      widget.style.position = "relative";
    }
    widget.appendChild(tooltipNode);

    return tooltipNode;
  }

  // Hides X-axis label tooltip.
  function hideBarAxisTooltip(tooltipNode) {
    if (!tooltipNode) {
      return;
    }

    tooltipNode.style.display = "none";
  }

  // Closes all custom X-axis tooltips across widgets.
  function hideOtherBarAxisTooltips() {
    var tooltipNodes = document.querySelectorAll(".national-grid-frontend-axis-tooltip");
    if (!tooltipNodes.length) {
      return;
    }

    tooltipNodes.forEach(function (node) {
      node.style.display = "none";
    });
  }

  // Returns X-axis label index when pointer is over the label area.
  function getXAxisLabelIndexFromPointer(chart, event) {
    if (!chart || !chart.scales || !chart.scales.x || !chart.chartArea || !chart.canvas) {
      return -1;
    }

    var xScale = chart.scales.x;
    var labels = chart.data && Array.isArray(chart.data.labels) ? chart.data.labels : [];
    if (!labels.length) {
      return -1;
    }

    var rect = chart.canvas.getBoundingClientRect();
    var x = event.clientX - rect.left;
    var y = event.clientY - rect.top;

    // Trigger only in the horizontal-axis label region below the bars.
    var labelZoneTop = chart.chartArea.bottom;
    var labelZoneBottom = xScale.bottom + 26;
    if (y < labelZoneTop || y > labelZoneBottom) {
      return -1;
    }

    var closestIndex = -1;
    var closestDistance = Number.POSITIVE_INFINITY;

    labels.forEach(function (_label, index) {
      var tickX = xScale.getPixelForTick(index);
      var distance = Math.abs(tickX - x);
      if (distance < closestDistance) {
        closestDistance = distance;
        closestIndex = index;
      }
    });

    if (closestIndex < 0) {
      return -1;
    }

    var previousTick = closestIndex > 0 ? xScale.getPixelForTick(closestIndex - 1) : null;
    var nextTick = closestIndex < labels.length - 1 ? xScale.getPixelForTick(closestIndex + 1) : null;
    var halfSpacing = 30;
    if (typeof previousTick === "number" && typeof nextTick === "number") {
      halfSpacing = Math.min(Math.abs(nextTick - xScale.getPixelForTick(closestIndex)), Math.abs(xScale.getPixelForTick(closestIndex) - previousTick)) / 2;
    } else if (typeof previousTick === "number") {
      halfSpacing = Math.abs(xScale.getPixelForTick(closestIndex) - previousTick) / 2;
    } else if (typeof nextTick === "number") {
      halfSpacing = Math.abs(nextTick - xScale.getPixelForTick(closestIndex)) / 2;
    }

    return closestDistance <= Math.max(12, halfSpacing) ? closestIndex : -1;
  }

  // Positions tooltip near cursor and keeps it within widget bounds.
  function positionBarAxisTooltip(widget, tooltipNode, clientX, clientY) {
    var widgetRect = widget.getBoundingClientRect();
    var left = clientX - widgetRect.left + 12;
    var top = clientY - widgetRect.top + 12;

    var tooltipWidth = tooltipNode.offsetWidth;
    var tooltipHeight = tooltipNode.offsetHeight;
    var maxLeft = widget.clientWidth - tooltipWidth - 8;
    var maxTop = widget.clientHeight - tooltipHeight - 8;

    tooltipNode.style.left = Math.max(8, Math.min(left, maxLeft)) + "px";
    tooltipNode.style.top = Math.max(8, Math.min(top, maxTop)) + "px";
  }

  // Normalizes mouse/touch/pointer event into client coordinates.
  function getClientPoint(event) {
    if (!event) {
      return null;
    }

    if (typeof event.clientX === "number" && typeof event.clientY === "number") {
      return {
        clientX: event.clientX,
        clientY: event.clientY,
      };
    }

    if (event.touches && event.touches.length) {
      return {
        clientX: event.touches[0].clientX,
        clientY: event.touches[0].clientY,
      };
    }

    if (event.changedTouches && event.changedTouches.length) {
      return {
        clientX: event.changedTouches[0].clientX,
        clientY: event.changedTouches[0].clientY,
      };
    }

    return null;
  }

  // Renders tooltip content for hovered bar-category label.
  function showBarAxisTooltip(widget, chart, tooltipNode, categoryIndex, event) {
    if (!chart || !tooltipNode) {
      return;
    }

    var labels = chart.data && Array.isArray(chart.data.labels) ? chart.data.labels : [];
    var label = typeof labels[categoryIndex] === "string" ? labels[categoryIndex] : "";
    var categoryTotal = getBarCategoryTotal(chart, categoryIndex);
    var totalGeneration = getBarTotalGeneration(chart);
    var percent = totalGeneration > 0 ? (categoryTotal / totalGeneration) * 100 : 0;

    tooltipNode.innerHTML =
      '<div style="font-weight:700; margin-bottom:4px;">' +
      escapeHtml(label) +
      "</div>" +
      "<div>Total: " +
      categoryTotal.toFixed(1) +
      " GW (" +
      formatPercent(percent) +
      "% of total generation)</div>";

    // Always close any previously open tooltips before showing the next one.
    hideOtherBarAxisTooltips();
    if (typeof chart.setActiveElements === "function") {
      chart.setActiveElements([]);
    }
    if (chart.tooltip && typeof chart.tooltip.setActiveElements === "function") {
      chart.tooltip.setActiveElements([], { x: 0, y: 0 });
    }
    if (typeof chart.update === "function") {
      chart.update("none");
    }

    tooltipNode.style.display = "block";
    positionBarAxisTooltip(widget, tooltipNode, event.clientX, event.clientY);
  }

  // Attaches hover listeners to show category totals when hovering X labels.
  function attachBarAxisLabelTooltip(widget, chart) {
    if (!widget || !chart || !chart.canvas) {
      return;
    }

    var tooltipNode = ensureBarAxisTooltipNode(widget);
    if (!tooltipNode) {
      return;
    }

    var handlePointerLikeMove = function (event) {
      var point = getClientPoint(event);
      if (!point) {
        hideBarAxisTooltip(tooltipNode);
        return;
      }

      var normalizedEvent = {
        clientX: point.clientX,
        clientY: point.clientY,
      };
      var categoryIndex = getXAxisLabelIndexFromPointer(chart, normalizedEvent);
      if (categoryIndex < 0) {
        hideBarAxisTooltip(tooltipNode);
        return;
      }

      showBarAxisTooltip(widget, chart, tooltipNode, categoryIndex, normalizedEvent);
    };

    chart.canvas.addEventListener("mousemove", handlePointerLikeMove);
    chart.canvas.addEventListener("touchstart", handlePointerLikeMove, { passive: true });
    chart.canvas.addEventListener("touchmove", handlePointerLikeMove, { passive: true });

    chart.canvas.addEventListener("mouseleave", function () {
      hideBarAxisTooltip(tooltipNode);
    });
    // Keep tooltip visible after tap; it closes on a different touch target or cancellation.
    chart.canvas.addEventListener("touchcancel", function () {
      hideBarAxisTooltip(tooltipNode);
    });
  }

  // Sums numeric dataset values, skipping non-finite entries.
  function getDatasetTotal(dataset) {
    if (!dataset || !Array.isArray(dataset.data)) {
      return 0;
    }

    return dataset.data.reduce(function (sum, value) {
      var numeric = Number(value);
      return Number.isFinite(numeric) ? sum + numeric : sum;
    }, 0);
  }

  // Formats percentage for UI labels: 0 and 100 without decimals, otherwise 1 decimal.
  function formatPercent(value) {
    var numeric = Number(value);
    if (!Number.isFinite(numeric)) {
      return "0";
    }

    if (Math.abs(numeric) < 0.05) {
      return "0";
    }

    if (Math.abs(100 - numeric) < 0.05) {
      return "100";
    }

    return numeric.toFixed(1);
  }

  // Returns non-negative numeric component value from pie map.
  function getComponentValue(pieMap, label) {
    var value = pieMap && Object.prototype.hasOwnProperty.call(pieMap, label) ? Number(pieMap[label]) : 0;
    if (!Number.isFinite(value)) {
      return 0;
    }

    return Math.max(0, value);
  }

  // Normalizes raw chart payload into pie chart input data.
  function buildPieData(chartData) {
    if (!chartData || !chartData.pie || typeof chartData.pie !== "object") {
      return null;
    }

    var labels = [];
    var values = [];
    var colors = [];

    COMPONENT_ORDER.forEach(function (label, index) {
      var value = getComponentValue(chartData.pie, label);
      if (value <= 0) {
        return;
      }

      labels.push(label);
      values.push(value);
      colors.push(colorByLabel[label] || palette[index % palette.length]);
    });

    if (!labels.length) {
      return null;
    }

    return {
      labels: labels,
      values: values,
      colors: colors,
      time:
        chartData.latest_five_minutes &&
        typeof chartData.latest_five_minutes.time === "string"
          ? chartData.latest_five_minutes.time
          : "",
    };
  }

  // Builds grouped stacked-bar data from the latest pie values.
  function buildBarData(chartData) {
    if (!chartData || !chartData.pie || typeof chartData.pie !== "object") {
      return null;
    }

    var labels = BAR_GROUPS.map(function (group) {
      return group.label;
    });

    var usedComponents = [];
    BAR_GROUPS.forEach(function (group) {
      group.components.forEach(function (component) {
        if (usedComponents.indexOf(component) === -1) {
          usedComponents.push(component);
        }
      });
    });

    var datasets = usedComponents.map(function (component, index) {
      var values = BAR_GROUPS.map(function (group) {
        if (group.components.indexOf(component) === -1) {
          return 0;
        }
        return getComponentValue(chartData.pie, component);
      });

      return {
        label: component,
        data: values,
        backgroundColor: colorByLabel[component] || palette[index % palette.length],
        borderWidth: 0,
        stack: "live-generation",
      };
    });

    return {
      labels: labels,
      datasets: datasets,
    };
  }

  // Renders one shared legend for both charts.
  function renderSharedLegend(widget, chartData) {
    var legendNode = widget.querySelector(".national-grid-frontend-legend");
    if (!legendNode) {
      return;
    }

    var pieMap = chartData && chartData.pie ? chartData.pie : {};
    var html = "";

    COMPONENT_ORDER.forEach(function (label, index) {
      if (!Object.prototype.hasOwnProperty.call(pieMap, label)) {
        return;
      }

      var colorClass = String(label)
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, "-")
        .replace(/^-+|-+$/g, "");
      html +=
        '<span class="national-grid-frontend-legend-item">' +
        '<span class="national-grid-frontend-legend-swatch national-grid-frontend-legend-swatch-' +
        colorClass +
        '"></span>' +
        '<span class="national-grid-frontend-legend-label">' +
        label +
        "</span>" +
        "</span>";
    });

    legendNode.innerHTML = html;
  }

  // Displays status text and toggles visibility/error state.
  function renderStatus(widget, text, isError) {
    var statusNode = widget.querySelector(".national-grid-frontend-status");
    if (!statusNode) {
      return;
    }
    var hasText = typeof text === "string" && text.trim() !== "";
    statusNode.textContent = hasText ? text : "";
    statusNode.classList.toggle("is-hidden", !hasText);
    statusNode.classList.toggle("is-error", !!isError);
  }

  // Parses UTC timestamp string from backend payload.
  function parseUtcDateTime(value) {
    if (typeof value !== "string" || !value) {
      return null;
    }

    var normalized = value.replace(" ", "T");
    var parsed = new Date(normalized + "Z");
    if (Number.isNaN(parsed.getTime())) {
      return null;
    }

    return parsed;
  }

  // Formats date into configured timezone components.
  function getTzParts(date) {
    var formatter = new Intl.DateTimeFormat("en-GB", {
      timeZone: config.timezone || "UTC",
      hour: "2-digit",
      minute: "2-digit",
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour12: false,
    });

    var parts = formatter.formatToParts(date);
    var map = {};
    parts.forEach(function (part) {
      map[part.type] = part.value;
    });

    return {
      hour: map.hour || "--",
      minute: map.minute || "--",
      year: map.year || "0000",
      month: map.month || "00",
      day: map.day || "00",
    };
  }

  // Renders the live heading with time/day in site timezone.
  function renderLiveHeading(widget, pointTime) {
    var headingNode = widget.querySelector(".national-grid-frontend-live-heading");
    if (!headingNode) {
      return;
    }

    var tzLabel = config.timezoneLabel || "UTC";
    var date = parseUtcDateTime(pointTime);
    if (!date) {
      headingNode.textContent =
        (config.liveHeadingPrefix || "National Grid:") +
        " --:-- " +
        (config.todayLabel || "Today") +
        " (" +
        tzLabel +
        ")" +
        (config.liveHeadingSuffix || " - Generation Mix and Type.");
      return;
    }

    var pointParts = getTzParts(date);
    var nowParts = getTzParts(new Date());
    var isToday =
      pointParts.year === nowParts.year &&
      pointParts.month === nowParts.month &&
      pointParts.day === nowParts.day;
    var dayLabel = isToday
      ? config.todayLabel || "Today"
      : pointParts.year + "-" + pointParts.month + "-" + pointParts.day;

    headingNode.textContent =
      (config.liveHeadingPrefix || "National Grid:") +
      " " +
      pointParts.hour +
      ":" +
      pointParts.minute +
      " " +
      dayLabel +
      " (" +
      tzLabel +
      ")" +
      (config.liveHeadingSuffix || " - Generation Mix and Type.");
  }

  // Renders clean power percentage derived from selected sources.
  function renderCleanPowerHeading(widget, chartData) {
    var headingNode = widget.querySelector(".national-grid-frontend-clean-power-heading");
    if (!headingNode) {
      return;
    }

    var pieMap = chartData && chartData.pie ? chartData.pie : {};
    var total = COMPONENT_ORDER.reduce(function (sum, label) {
      return sum + getComponentValue(pieMap, label);
    }, 0);

    var cleanTotal = CLEAN_POWER_COMPONENTS.reduce(function (sum, label) {
      return sum + getComponentValue(pieMap, label);
    }, 0);

    var percentage = total > 0 ? (cleanTotal / total) * 100 : 0;
    headingNode.innerHTML =
      'Live Percentage Clean Power: <span class="national-grid-frontend-clean-power-value">' +
      formatPercent(percentage) +
      "%</span>";
  }

  // Creates the live generation pie chart instance.
  function createPieChart(widget, chartData) {
    var canvas = widget.querySelector(".national-grid-frontend-chart-pie");
    if (!canvas) {
      return null;
    }

    var pieData = buildPieData(chartData);
    if (!pieData) {
      return null;
    }

    return new window.Chart(canvas, {
      type: "pie",
      data: {
        labels: pieData.labels,
        datasets: [
          {
            data: pieData.values,
            backgroundColor: pieData.colors,
            borderColor: "#ffffff",
            borderWidth: 1,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: CHART_ANIMATION,
        cutout: "52%",
        interaction: {
          mode: "nearest",
          intersect: true,
        },
        plugins: {
          legend: {
            display: false,
          },
          tooltip: {
            callbacks: {
              title: function () {
                return "";
              },
              label: function (context) {
                var dataset = context.dataset || null;
                var total = getDatasetTotal(dataset);
                var rawValue = Number(context.raw);
                var value = Number.isFinite(rawValue) ? rawValue : 0;
                var percent = total > 0 ? (value / total) * 100 : 0;
                var label = context.label || "";
                return (
                  label +
                  ": " +
                  value.toFixed(1) +
                  " GW (" +
                  formatPercent(percent) +
                  "%)"
                );
              },
            },
          },
        },
      },
      plugins: [piePercentLabelsPlugin, pieCenterTotalPlugin],
    });
  }

  // Creates the grouped stacked bar chart instance.
  function createBarChart(widget, chartData) {
    var canvas = widget.querySelector(".national-grid-frontend-chart-bar");
    if (!canvas) {
      return null;
    }

    var barData = buildBarData(chartData);
    if (!barData) {
      return null;
    }

    var isMobileViewport =
      typeof window !== "undefined" &&
      window.matchMedia &&
      window.matchMedia("(max-width: 767px)").matches;

    var chart = new window.Chart(canvas, {
      type: "bar",
      data: barData,
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: CHART_ANIMATION,
        scales: {
          x: {
            stacked: true,
            grid: {
              display: false,
            },
          },
          y: {
            stacked: true,
            beginAtZero: true,
            display: true,
            title: {
              display: true,
              text: "Live Generation (GW)",
              font: {
                weight: "700",
              },
              color: "#000000"
            },
          },
        },
        datasets: isMobileViewport
          ? {
              bar: {
                // Keep bars wide on mobile but preserve a small gap between columns.
                categoryPercentage: 0.93,
                barPercentage: 1,
              },
            }
          : {},
        plugins: {
          legend: {
            display: false,
          },
          tooltip: {
            callbacks: {
              title: function () {
                return "";
              },
              label: function (context) {
                var value = Number(context.raw);
                var safeValue = Number.isFinite(value) ? value : 0;
                var categoryTotal = getBarCategoryTotal(context.chart, context.dataIndex);
                var percent = categoryTotal > 0 ? (safeValue / categoryTotal) * 100 : 0;
                var labels = context.chart && context.chart.data && Array.isArray(context.chart.data.labels)
                  ? context.chart.data.labels
                  : [];
                var categoryLabel = typeof labels[context.dataIndex] === "string" ? labels[context.dataIndex] : "Category";
                return (
                  (context.dataset.label || "") +
                  ": " +
                  safeValue.toFixed(1) +
                  " GW (" +
                  formatPercent(percent) +
                  "% of " +
                  categoryLabel +
                  ")"
                );
              },
            },
          },
        },
      },
      plugins: [barSegmentLabelsPlugin],
    });

    attachBarAxisLabelTooltip(widget, chart);
    return chart;
  }

  // Updates pie chart data and returns associated point timestamp.
  function updatePieChart(chart, chartData) {
    if (!chart) {
      return "";
    }

    var pieData = buildPieData(chartData);
    if (!pieData) {
      chart.data.labels = [];
      chart.data.datasets = [{ data: [] }];
      chart.update();
      return "";
    }

    chart.data.labels = pieData.labels;
    chart.data.datasets = [
      {
        data: pieData.values,
        backgroundColor: pieData.colors,
        borderColor: "#ffffff",
        borderWidth: 1,
      },
    ];
    chart.update();

    return pieData.time || "";
  }

  // Updates bar chart series from latest payload.
  function updateBarChart(chart, chartData) {
    if (!chart) {
      return;
    }

    var barData = buildBarData(chartData);
    if (!barData) {
      chart.data.labels = [];
      chart.data.datasets = [];
      chart.update();
      return;
    }

    chart.data.labels = barData.labels;
    chart.data.datasets = barData.datasets;
    chart.update();
  }

  // Fetches latest chart data via AJAX and updates widget UI.
  function fetchData(widget, chartState) {
    var formData = new window.FormData();

    formData.append("action", config.action);
    formData.append("nonce", config.nonce);

    return window
      .fetch(config.ajaxUrl, {
        method: "POST",
        body: formData,
        credentials: "same-origin",
      })
      .then(function (response) {
        return response.json();
      })
      .then(function (response) {
        if (!response || !response.success || !response.data) {
          throw new Error(config.errorMessage);
        }

        var nextData = response.data.data || {};
        if (!chartState.pieChart) {
          chartState.pieChart = createPieChart(widget, nextData);
        } else {
          chartState.lastPointTime = updatePieChart(chartState.pieChart, nextData);
        }

        if (!chartState.barChart) {
          chartState.barChart = createBarChart(widget, nextData);
        } else {
          updateBarChart(chartState.barChart, nextData);
        }

        renderLiveHeading(widget, chartState.lastPointTime);
        renderCleanPowerHeading(widget, nextData);
        renderSharedLegend(widget, nextData);

        renderStatus(widget, "", false);
      })
      .catch(function () {
        renderStatus(widget, config.errorMessage, true);
      });
  }

  // Initializes charts and periodic refresh for each widget instance.
  widgets.forEach(function (widget) {
    var payloadNode = widget.querySelector(".national-grid-frontend-payload");
    if (!payloadNode) {
      return;
    }

    var payload;
    try {
      payload = JSON.parse(payloadNode.textContent || "{}");
    } catch (error) {
      renderStatus(widget, config.errorMessage, true);
      return;
    }

    var chartData = payload && payload.chartData ? payload.chartData : {};
    var chartState = {
      pieChart: createPieChart(widget, chartData),
      barChart: createBarChart(widget, chartData),
      lastPointTime: "",
    };

    renderSharedLegend(widget, chartData);
    renderLiveHeading(widget, chartState.lastPointTime);
    renderCleanPowerHeading(widget, chartData);

    if (chartState.pieChart || chartState.barChart) {
      var initialPie = buildPieData(chartData);
      if (initialPie && initialPie.time) {
        chartState.lastPointTime = initialPie.time;
      }
      renderLiveHeading(widget, chartState.lastPointTime);
      renderStatus(widget, "", false);
    } else {
      renderStatus(widget, config.noDataMessage, false);
    }

    var intervalMinutes = parseInt(config.timeoutMinutes, 10) || 5;
    // Keeps widget data fresh using backend-configured refresh interval.
    window.setInterval(function () {
      fetchData(widget, chartState);
    }, intervalMinutes * 60 * 1000);
  });
})();

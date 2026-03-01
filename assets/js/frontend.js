(function () {
  "use strict";

  if (typeof window.nationalGridFrontend === "undefined") {
    return;
  }

  var config = window.nationalGridFrontend;
  var widgets = document.querySelectorAll(".national-grid-frontend");
  if (!widgets.length) {
    return;
  }

  var palette = [
    "#1b4965",
    "#2a9d8f",
    "#e76f51",
    "#264653",
    "#f4a261",
    "#457b9d",
    "#6d597a",
    "#8ab17d",
    "#b56576",
    "#577590",
    "#f94144",
    "#43aa8b",
    "#90be6d",
    "#277da1",
  ];
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
  var piePercentLabelsPlugin = {
    id: "nationalGridPiePercentLabels",
    afterDatasetsDraw: function (chart) {
      var dataset = chart.data && chart.data.datasets ? chart.data.datasets[0] : null;
      if (!dataset || !Array.isArray(dataset.data) || !dataset.data.length) {
        return;
      }

      var total = dataset.data.reduce(function (sum, value) {
        var numeric = Number(value);
        return Number.isFinite(numeric) ? sum + numeric : sum;
      }, 0);
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
        if (percent < 3) {
          return;
        }

        var midAngle = (arc.startAngle + arc.endAngle) / 2;
        var labelRadius = arc.outerRadius * 0.72;
        var x = arc.x + Math.cos(midAngle) * labelRadius;
        var y = arc.y + Math.sin(midAngle) * labelRadius;
        ctx.fillText(percent.toFixed(1) + "%", x, y);
      });

      ctx.restore();
    },
  };
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

      var innerCircleRadius = outerRadius * 0.45;
      var ctx = chart.ctx;
      ctx.save();
      ctx.beginPath();
      ctx.arc(centerX, centerY, innerCircleRadius, 0, Math.PI * 2);
      ctx.fillStyle = "#ffffff";
      ctx.fill();

      ctx.fillStyle = "#334e68";
      ctx.textAlign = "center";
      ctx.textBaseline = "middle";
      ctx.font = "600 11px sans-serif";
      ctx.fillText("Total Generation", centerX, centerY - 10);

      ctx.fillStyle = "#102a43";
      ctx.font = "700 14px sans-serif";
      ctx.fillText(total.toFixed(1) + " GW", centerX, centerY + 10);
      ctx.restore();
    },
  };

  function buildPieData(chartData) {
    if (!chartData || !chartData.pie || typeof chartData.pie !== "object") {
      return null;
    }

    var mappedLabels = [];
    var mappedValues = [];
    var mappedColors = [];
    Object.keys(chartData.pie).forEach(function (label, index) {
      var rawValue = Number(chartData.pie[label]);
      if (!Number.isFinite(rawValue)) {
        return;
      }

      var safeValue = Math.max(0, rawValue);
      if (safeValue <= 0) {
        return;
      }

      mappedLabels.push(String(label));
      mappedValues.push(safeValue);
      mappedColors.push(
        colorByLabel[label] || palette[index % palette.length]
      );
    });

    if (!mappedLabels.length) {
      return null;
    }

    return {
      labels: mappedLabels,
      values: mappedValues,
      colors: mappedColors,
      time:
        chartData.latest_five_minutes &&
        typeof chartData.latest_five_minutes.time === "string"
          ? chartData.latest_five_minutes.time
          : "",
    };
  }

  function getDatasetTotal(dataset) {
    if (!dataset || !Array.isArray(dataset.data)) {
      return 0;
    }

    return dataset.data.reduce(function (sum, value) {
      var numeric = Number(value);
      return Number.isFinite(numeric) ? sum + numeric : sum;
    }, 0);
  }

  function renderStatus(widget, text, isError) {
    var statusNode = widget.querySelector(".national-grid-frontend-status");
    if (!statusNode) {
      return;
    }
    statusNode.textContent = text || "";
    statusNode.classList.toggle("is-error", !!isError);
  }

  function createChart(widget, payload) {
    var canvas = widget.querySelector(".national-grid-frontend-chart");
    if (!canvas || !payload || !payload.chartData) {
      return null;
    }

    var pieData = buildPieData(payload.chartData);
    if (!pieData) {
      renderStatus(widget, config.noDataMessage, false);
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
        plugins: {
          legend: {
            position: "bottom",
          },
          tooltip: {
            callbacks: {
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
                  percent.toFixed(1) +
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

  function updateChart(chart, data) {
    if (!chart || !data) {
      return;
    }

    var pieData = buildPieData(data);
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

  function fetchData(widget, chartState) {
    var formData = new window.FormData();
    var limit = parseInt(widget.getAttribute("data-limit"), 10) || 48;

    formData.append("action", config.action);
    formData.append("nonce", config.nonce);
    formData.append("limit", limit);

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
        if (!chartState.instance) {
          chartState.instance = createChart(widget, { chartData: nextData });
        } else {
          chartState.lastPointTime = updateChart(chartState.instance, nextData);
        }

        var pointTime = chartState.lastPointTime ? " | Point: " + chartState.lastPointTime : "";
        renderStatus(
          widget,
          config.updatedAtLabel + (response.data.updatedAt || "") + pointTime,
          false
        );
      })
      .catch(function () {
        renderStatus(widget, config.errorMessage, true);
      });
  }

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

    var chartState = { instance: createChart(widget, payload), lastPointTime: "" };
    if (chartState.instance) {
      var initialPie = buildPieData(payload.chartData || {});
      if (initialPie && initialPie.time) {
        chartState.lastPointTime = initialPie.time;
      }
      var initialPointTime = chartState.lastPointTime ? " | Point: " + chartState.lastPointTime : "";
      renderStatus(
        widget,
        config.updatedAtLabel + new Date().toISOString().slice(0, 19).replace("T", " ") + initialPointTime,
        false
      );
    }

    var intervalMinutes = parseInt(config.timeoutMinutes, 10) || 5;
    window.setInterval(function () {
      fetchData(widget, chartState);
    }, intervalMinutes * 60 * 1000);
  });
})();

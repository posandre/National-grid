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
    "Storage",
    "Interconnectors",
    "Biomass",
    "Nuclear",
    "Hydroelectric",
    "Solar",
    "Wind",
    "Gas",
  ];

  // Source labels included in the clean power percentage metric.
  var CLEAN_POWER_COMPONENTS = ["Wind", "Solar", "Hydroelectric", "Biomass", "Nuclear"];

  // Category definitions used to build grouped stacked bars.
  var BAR_GROUPS = [
    { label: "Renewable", components: ["Wind", "Solar", "Hydroelectric"] },
    { label: "Low Carbon", components: ["Biomass", "Nuclear"] },
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
        ctx.fillText(percent.toFixed(1) + "%", x, y);
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
      ctx.fillText(total.toFixed(2) + " GW", centerX, centerY + 10);
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
          ctx.fillText(value.toFixed(2) + " GW", bar.x, bar.y + barHeight / 2);
        });
      });

      ctx.restore();
    },
  };

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
        label +
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
      percentage.toFixed(1) +
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
        plugins: {
          legend: {
            display: false,
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
                  value.toFixed(2) +
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

    return new window.Chart(canvas, {
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
        plugins: {
          legend: {
            display: false,
          },
          tooltip: {
            callbacks: {
              label: function (context) {
                var value = Number(context.raw);
                var safeValue = Number.isFinite(value) ? value : 0;
                return (context.dataset.label || "") + ": " + safeValue.toFixed(2) + " GW";
              },
            },
          },
        },
      },
      plugins: [barSegmentLabelsPlugin],
    });
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

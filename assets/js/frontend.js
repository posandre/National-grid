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

  function toHumanLabel(key) {
    return String(key || "")
      .replace(/_/g, " ")
      .replace(/\b\w/g, function (char) {
        return char.toUpperCase();
      });
  }

  function buildPieData(chartData) {
    if (!chartData || !chartData.latest_five_minutes) {
      return null;
    }

    var row = chartData.latest_five_minutes;
    var labels = [];
    var values = [];
    var colors = [];

    Object.keys(row).forEach(function (key, index) {
      if (key === "time") {
        return;
      }

      var numericValue = Number(row[key]);
      if (!Number.isFinite(numericValue)) {
        return;
      }
      var value = Math.max(0, numericValue);
      if (value <= 0) {
        return;
      }

      labels.push(toHumanLabel(key));
      values.push(value);
      colors.push(palette[index % palette.length]);
    });

    if (!labels.length) {
      return null;
    }

    return {
      labels: labels,
      values: values,
      colors: colors,
      time: typeof row.time === "string" ? row.time : "",
    };
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
        },
      },
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
